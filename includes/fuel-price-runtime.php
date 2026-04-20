<?php
/**
 * MotorLink Fuel Price Service
 * ----------------------------------------------------------------------------
 * Resolves the latest fuel prices using a live source (globalpetrolprices.com)
 * with a cached + DB fallback strategy, plus display currency adaptation.
 *
 * Exposes a single public surface:
 *   - motorlink_resolve_fuel_price_snapshot($db, $options)
 *   - motorlink_extract_public_fuel_price_meta($snapshot)
 *   - motorlink_format_fuel_prices_for_api($snapshot)
 *
 * Admin-controlled settings (stored in `settings` table):
 *   - fuel_price_source          : 'live_with_fallback' (default) | 'db_only' | 'live_only'
 *   - fuel_price_display_currency: 'local' (default) | 'usd'
 *   - fuel_price_cache_hours     : integer (default 6)
 *
 * Country and currency are read from `site_settings` (runtime config):
 *   - fuel_price_country_slug
 *   - country_name
 *   - currency_code, currency_symbol
 */

if (!function_exists('motorlink_resolve_fuel_price_snapshot')) {

    /**
     * Resolve the active fuel price snapshot.
     * Returns a normalized array with keys:
     *   - prices            : array of per-fuel-type rows (petrol/diesel/…)
     *   - meta              : source info (live|cache|db, last_updated, currency, etc.)
     *   - display_currency  : { code, symbol, source }
     *   - is_live           : bool
     *   - public_notice     : short message for UI badges
     */
    function motorlink_resolve_fuel_price_snapshot($db, array $options = [])
    {
        $runtimeConfig = function_exists('getSiteRuntimeConfig')
            ? getSiteRuntimeConfig($db)
            : [];

        $localCode    = strtoupper(trim((string)($runtimeConfig['currency_code'] ?? 'MWK'))) ?: 'MWK';
        $localSymbol  = trim((string)($runtimeConfig['currency_symbol'] ?? $localCode)) ?: $localCode;
        $countryName  = trim((string)($runtimeConfig['country_name'] ?? ''));
        $countrySlug  = trim((string)($runtimeConfig['fuel_price_country_slug'] ?? $countryName));

        $priceSource      = motorlink_fuel_setting($db, 'fuel_price_source', 'live_with_fallback');
        $displayCurrency  = strtolower((string)motorlink_fuel_setting($db, 'fuel_price_display_currency', 'local'));
        $cacheHours       = max(1, (int)motorlink_fuel_setting($db, 'fuel_price_cache_hours', 6));
        $forceRefresh     = !empty($options['force_refresh']);

        // Allow per-call overrides (admin previews, tests)
        if (isset($options['price_source']) && $options['price_source'] !== '') {
            $priceSource = (string)$options['price_source'];
        }
        if (isset($options['display_currency']) && $options['display_currency'] !== '') {
            $displayCurrency = strtolower((string)$options['display_currency']);
        }

        $displayCode   = ($displayCurrency === 'usd') ? 'USD' : $localCode;
        $displaySymbol = ($displayCurrency === 'usd') ? '$'   : $localSymbol;

        $live = null;
        $liveError = null;

        if (in_array($priceSource, ['live_with_fallback', 'live_only'], true) && $countrySlug !== '') {
            // Try cache (unless force_refresh). Cache lives in fuel_prices as
            // rows with source='globalpetrolprices.com' + recent last_updated.
            $cached = $forceRefresh ? null : motorlink_fuel_load_recent_cache($db, $cacheHours);

            if ($cached && count($cached) >= 2) {
                $live = [
                    'prices'       => $cached,
                    'source_label' => 'GlobalPetrolPrices.com (cached)',
                    'source_key'   => 'cache',
                    'last_updated' => $cached[0]['last_updated'] ?? null,
                    'published'    => $cached[0]['date'] ?? null
                ];
            } else {
                try {
                    $fetched = motorlink_fuel_fetch_from_globalpetrolprices($countrySlug, $localCode);
                    if (!empty($fetched['prices'])) {
                        motorlink_fuel_persist_to_db($db, $fetched['prices'], $fetched['source_url'] ?? null);
                        $live = [
                            'prices'       => motorlink_fuel_load_recent_cache($db, $cacheHours) ?: $fetched['prices'],
                            'source_label' => 'GlobalPetrolPrices.com (live)',
                            'source_key'   => 'live',
                            'last_updated' => date('Y-m-d H:i:s'),
                            'published'    => $fetched['published'] ?? date('Y-m-d')
                        ];
                    }
                } catch (Exception $e) {
                    $liveError = $e->getMessage();
                    error_log('motorlink fuel live fetch failed: ' . $liveError);
                }
            }
        }

        // Fallback to database rows
        if (!$live && $priceSource !== 'live_only') {
            $dbRows = motorlink_fuel_load_latest_from_db($db);
            if (!empty($dbRows)) {
                $live = [
                    'prices'       => $dbRows,
                    'source_label' => (string)($dbRows[0]['source'] ?? 'Stored fuel prices'),
                    'source_key'   => 'db',
                    'last_updated' => $dbRows[0]['last_updated'] ?? null,
                    'published'    => $dbRows[0]['date'] ?? null
                ];
            }
        }

        if (!$live) {
            return [
                'prices'           => [],
                'meta'             => [
                    'source_key'   => 'none',
                    'source_label' => 'Fuel price feed unavailable',
                    'last_updated' => null,
                    'published'    => null,
                    'live_error'   => $liveError
                ],
                'display_currency' => [
                    'code'   => $displayCode,
                    'symbol' => $displaySymbol,
                    'source' => ($displayCurrency === 'usd') ? 'usd' : 'primary'
                ],
                'is_live'          => false,
                'public_notice'    => 'Live fuel prices are temporarily unavailable. Please try again shortly.'
            ];
        }

        // Decorate each price row with display-currency fields
        $decorated = [];
        foreach ($live['prices'] as $row) {
            $priceMwk = isset($row['price_per_liter_mwk']) ? (float)$row['price_per_liter_mwk'] : 0.0;
            $priceUsd = isset($row['price_per_liter_usd']) && $row['price_per_liter_usd'] !== null
                ? (float)$row['price_per_liter_usd']
                : null;

            if ($displayCurrency === 'usd') {
                $displayPrice = $priceUsd !== null ? $priceUsd : null;
                $displaySource = 'usd';
            } else {
                $displayPrice = $priceMwk;
                $displaySource = 'primary';
            }

            $decorated[] = array_merge($row, [
                'display_currency_code'   => $displayCode,
                'display_currency_symbol' => $displaySymbol,
                'display_currency_source' => $displaySource,
                'display_price_per_liter' => $displayPrice
            ]);
        }

        $isLive = in_array($live['source_key'], ['live', 'cache'], true);

        $notice = $isLive
            ? 'Showing live fuel prices sourced from GlobalPetrolPrices.com.'
            : 'Live feed unavailable — showing the last stored fuel prices.';

        return [
            'prices'           => $decorated,
            'meta'             => [
                'source_key'              => $live['source_key'],
                'source_label'            => $live['source_label'],
                'last_updated'            => $live['last_updated'],
                'published_date'          => $live['published'],
                'display_currency_code'   => $displayCode,
                'display_currency_symbol' => $displaySymbol,
                'display_currency_source' => ($displayCurrency === 'usd') ? 'usd' : 'primary',
                'public_notice'           => $notice,
                'country'                 => $countryName ?: null,
                'primary_currency_code'   => $localCode,
                'primary_currency_symbol' => $localSymbol
            ],
            'display_currency' => [
                'code'   => $displayCode,
                'symbol' => $displaySymbol,
                'source' => ($displayCurrency === 'usd') ? 'usd' : 'primary'
            ],
            'is_live'          => $isLive,
            'public_notice'    => $notice
        ];
    }

    /**
     * Build a lightweight meta dict suitable for API responses (no prices).
     */
    function motorlink_extract_public_fuel_price_meta(array $snapshot)
    {
        return ($snapshot['meta'] ?? []) + [
            'is_live'       => (bool)($snapshot['is_live'] ?? false),
            'public_notice' => $snapshot['public_notice'] ?? ''
        ];
    }

    /**
     * Pick the active price row for a given fuel type from a resolved snapshot.
     */
    function motorlink_pick_fuel_row(array $snapshot, $fuelType)
    {
        $fuelType = strtolower(trim((string)$fuelType));
        foreach (($snapshot['prices'] ?? []) as $row) {
            if (strtolower((string)($row['fuel_type'] ?? '')) === $fuelType) {
                return $row;
            }
        }
        return null;
    }

    // =========================================================================
    // Internal helpers (prefixed motorlink_fuel_*)
    // =========================================================================

    function motorlink_fuel_setting($db, $key, $default)
    {
        if (function_exists('getPlatformSetting')) {
            $value = getPlatformSetting($db, $key, null);
            return ($value === null || $value === '') ? $default : $value;
        }

        try {
            $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
            $stmt->execute([$key]);
            $value = $stmt->fetchColumn();
            return ($value === false || $value === null || $value === '') ? $default : $value;
        } catch (Exception $e) {
            return $default;
        }
    }

    function motorlink_fuel_load_recent_cache($db, $maxHours)
    {
        try {
            $stmt = $db->prepare(
                "SELECT fuel_type, price_per_liter_mwk, price_per_liter_usd, currency,
                        source, source_url, last_updated, date
                   FROM fuel_prices
                  WHERE is_active = 1
                    AND source LIKE '%globalpetrolprices%'
                    AND last_updated >= (NOW() - INTERVAL ? HOUR)
               ORDER BY last_updated DESC, fuel_type ASC
                  LIMIT 10"
            );
            $stmt->execute([(int)$maxHours]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            return motorlink_fuel_dedupe_by_type($rows);
        } catch (Exception $e) {
            error_log('motorlink_fuel_load_recent_cache error: ' . $e->getMessage());
            return [];
        }
    }

    function motorlink_fuel_load_latest_from_db($db)
    {
        try {
            $stmt = $db->prepare(
                "SELECT fuel_type, price_per_liter_mwk, price_per_liter_usd, currency,
                        source, source_url, last_updated, date
                   FROM fuel_prices
                  WHERE is_active = 1
               ORDER BY date DESC, last_updated DESC, fuel_type ASC
                  LIMIT 20"
            );
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            return motorlink_fuel_dedupe_by_type($rows);
        } catch (Exception $e) {
            error_log('motorlink_fuel_load_latest_from_db error: ' . $e->getMessage());
            return [];
        }
    }

    function motorlink_fuel_dedupe_by_type(array $rows)
    {
        $seen = [];
        $out = [];
        foreach ($rows as $row) {
            $type = strtolower((string)($row['fuel_type'] ?? ''));
            if ($type === '' || isset($seen[$type])) {
                continue;
            }
            $seen[$type] = true;
            $out[] = $row;
        }
        return $out;
    }

    function motorlink_fuel_persist_to_db($db, array $prices, $sourceUrl = null)
    {
        $today = date('Y-m-d');

        try {
            $selectStmt = $db->prepare("SELECT id FROM fuel_prices WHERE fuel_type = ? AND date = ? LIMIT 1");
            $updateStmt = $db->prepare(
                "UPDATE fuel_prices
                    SET price_per_liter_mwk = ?,
                        price_per_liter_usd = ?,
                        currency = ?,
                        is_active = 1,
                        source = 'globalpetrolprices.com',
                        source_url = ?,
                        last_updated = NOW()
                  WHERE id = ?"
            );
            $insertStmt = $db->prepare(
                "INSERT INTO fuel_prices
                    (fuel_type, price_per_liter_mwk, price_per_liter_usd, currency,
                     date, is_active, source, source_url, last_updated)
                 VALUES (?, ?, ?, ?, ?, 1, 'globalpetrolprices.com', ?, NOW())"
            );

            foreach ($prices as $row) {
                $type = strtolower((string)($row['fuel_type'] ?? ''));
                if ($type === '' || !in_array($type, ['petrol', 'diesel', 'lpg', 'cng'], true)) {
                    continue;
                }

                $mwk = isset($row['price_per_liter_mwk']) ? (float)$row['price_per_liter_mwk'] : 0.0;
                if ($mwk <= 0) {
                    continue;
                }
                $usd = isset($row['price_per_liter_usd']) && $row['price_per_liter_usd'] !== null
                    ? (float)$row['price_per_liter_usd']
                    : null;
                $currency = strtoupper(trim((string)($row['currency'] ?? 'MWK'))) ?: 'MWK';

                $selectStmt->execute([$type, $today]);
                $existingId = $selectStmt->fetchColumn();

                if ($existingId) {
                    $updateStmt->execute([$mwk, $usd, $currency, $sourceUrl, $existingId]);
                } else {
                    $insertStmt->execute([$type, $mwk, $usd, $currency, $today, $sourceUrl]);
                }
            }
        } catch (Exception $e) {
            error_log('motorlink_fuel_persist_to_db error: ' . $e->getMessage());
        }
    }

    /**
     * Fetch current gasoline + diesel prices from globalpetrolprices.com.
     *
     * GlobalPetrolPrices renders the headline price inside markup such as:
     *   <div id="outsideLinks">
     *     <div id="graphPageLeft">
     *       <p><strong>2.50</strong> ...</p>
     *     </div>
     *   </div>
     * and the conversion table includes USD + local currency prices.
     *
     * We parse with DOMDocument and fall back to regex if parsing fails.
     */
    function motorlink_fuel_fetch_from_globalpetrolprices($countrySlug, $localCurrencyCode)
    {
        $slug = rawurlencode($countrySlug);
        $urls = [
            'petrol' => "https://www.globalpetrolprices.com/{$slug}/gasoline_prices/",
            'diesel' => "https://www.globalpetrolprices.com/{$slug}/diesel_prices/"
        ];

        $prices = [];
        $published = null;
        $firstSourceUrl = null;

        foreach ($urls as $fuelType => $url) {
            $html = motorlink_fuel_http_get($url);
            if ($html === null) {
                continue;
            }

            $parsed = motorlink_fuel_parse_globalpetrolprices_html($html, $localCurrencyCode);
            if ($parsed === null) {
                continue;
            }

            // parsed = ['usd_per_liter'=>x, 'local_per_liter'=>y, 'published'=>'YYYY-MM-DD']
            if (!empty($parsed['local_per_liter']) && $parsed['local_per_liter'] > 0) {
                $prices[] = [
                    'fuel_type'           => $fuelType,
                    'price_per_liter_mwk' => round((float)$parsed['local_per_liter'], 2),
                    'price_per_liter_usd' => !empty($parsed['usd_per_liter'])
                        ? round((float)$parsed['usd_per_liter'], 4)
                        : null,
                    'currency'            => $localCurrencyCode,
                    'date'                => $parsed['published'] ?? date('Y-m-d')
                ];

                if ($firstSourceUrl === null) {
                    $firstSourceUrl = $url;
                }
                if ($published === null) {
                    $published = $parsed['published'] ?? null;
                }
            }
        }

        if (empty($prices)) {
            throw new Exception('Could not parse fuel prices from GlobalPetrolPrices.com');
        }

        return [
            'prices'     => $prices,
            'source_url' => $firstSourceUrl,
            'published'  => $published
        ];
    }

    function motorlink_fuel_http_get($url)
    {
        if (!function_exists('curl_init')) {
            return null;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 4,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_ENCODING       => '', // accept gzip/deflate
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
                'Cache-Control: no-cache',
                'Pragma: no-cache',
                'Upgrade-Insecure-Requests: 1'
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($code !== 200 || !is_string($body) || $body === '') {
            if ($err) {
                error_log("motorlink fuel http_get failed ({$code}) for {$url}: {$err}");
            }
            return null;
        }
        return $body;
    }

    /**
     * Parse a GlobalPetrolPrices country gasoline/diesel page.
     * Returns ['local_per_liter' => float, 'usd_per_liter' => float, 'published' => 'YYYY-MM-DD']
     * or null when the price cannot be extracted with confidence.
     */
    function motorlink_fuel_parse_globalpetrolprices_html($html, $localCurrencyCode)
    {
        $localCurrencyCode = strtoupper($localCurrencyCode);

        // Extract published date, e.g. "The average price of gasoline ... 07-Apr-2026"
        $published = null;
        if (preg_match('/(\d{1,2}-[A-Za-z]{3}-\d{4})/', $html, $dm)) {
            $ts = strtotime($dm[1]);
            if ($ts) {
                $published = date('Y-m-d', $ts);
            }
        }

        $usd = null;
        $local = null;

        // The conversion table has rows like:
        //   <tr><td>USD</td><td>... 1.234 ...</td><td>... 4.671 ...</td></tr>
        //   <tr><td>MWK</td><td>... 2,100.000 ...</td><td>... 7,949.850 ...</td></tr>
        if (preg_match_all(
            '/<tr[^>]*>\s*<td[^>]*>\s*([A-Z]{3})\s*<\/td>\s*<td[^>]*>\s*([\d,\.]+)\s*<\/td>/si',
            $html,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $code = strtoupper($m[1]);
                $value = (float)str_replace(',', '', $m[2]);
                if ($value <= 0) {
                    continue;
                }
                if ($code === 'USD' && $usd === null) {
                    $usd = $value;
                }
                if ($code === $localCurrencyCode && $local === null) {
                    $local = $value;
                }
            }
        }

        // Headline price fallback: e.g. '<strong>2.50</strong>' near 'per liter'
        if ($local === null || $usd === null) {
            if (preg_match('/<p[^>]*>\s*<strong[^>]*>\s*([\d\.,]+)\s*<\/strong>/si', $html, $pm)) {
                $headline = (float)str_replace(',', '', $pm[1]);
                // The headline is usually in the page's primary currency (USD on the English site).
                if ($headline > 0 && $usd === null) {
                    $usd = $headline;
                }
            }
        }

        if ($local === null && $usd === null) {
            return null;
        }

        // If local is missing but we have USD + a known pair on the page, try alt table row variants.
        if ($local === null && $usd !== null) {
            // Look for "1 USD = X <LOCAL>" conversion hint if present.
            if (preg_match('/1\s*USD\s*=\s*([\d\.,]+)\s*' . preg_quote($localCurrencyCode, '/') . '/i', $html, $cm)) {
                $rate = (float)str_replace(',', '', $cm[1]);
                if ($rate > 0) {
                    $local = $usd * $rate;
                }
            }
        }

        if ($local === null || $local <= 0) {
            return null;
        }

        return [
            'local_per_liter' => $local,
            'usd_per_liter'   => $usd,
            'published'       => $published
        ];
    }
}
