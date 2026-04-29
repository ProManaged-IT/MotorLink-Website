# API Keys Configuration

## Security Notice

API keys are now stored in the database (`site_settings`) and are loaded server-side at runtime.

- AI keys are never read from source files.
- Google Maps key is no longer hardcoded in frontend code.
- Frontend fetches runtime map config from `api.php?action=get_public_client_config`.

## Setup Instructions

### Required `site_settings` keys

Add/update the following keys in the `site_settings` table:

- `openai_api_key`
- `deepseek_api_key`
- `google_maps_api_key`
- `google_maps_map_id`
- `google_places_scraper_api_key` (optional; keep empty unless you intentionally run scraper jobs)

Example SQL:

```sql
UPDATE site_settings SET setting_value = 'YOUR_OPENAI_KEY' WHERE setting_key = 'openai_api_key';
UPDATE site_settings SET setting_value = 'YOUR_DEEPSEEK_KEY' WHERE setting_key = 'deepseek_api_key';
UPDATE site_settings SET setting_value = 'YOUR_GOOGLE_MAPS_KEY' WHERE setting_key = 'google_maps_api_key';
UPDATE site_settings SET setting_value = 'YOUR_GOOGLE_MAPS_MAP_ID' WHERE setting_key = 'google_maps_map_id';
UPDATE site_settings SET setting_value = 'YOUR_SCRAPER_ONLY_GOOGLE_PLACES_KEY' WHERE setting_key = 'google_places_scraper_api_key';
```

## Files

- **`database/p601229_motorlinkmalawi_db.sql`** - Includes placeholder `site_settings` rows for integration keys
- **`api.php`** - Serves allowlisted runtime client config (`get_public_client_config`)
- **`ai-car-chat-api.php`** and **`ai-learning-api.php`** - Load AI keys from DB only

## Current API Keys

- **OpenAI API Key**
  - Location: `site_settings.setting_key = 'openai_api_key'`
- **DeepSeek API Key**
  - Location: `site_settings.setting_key = 'deepseek_api_key'`
- **Google Maps API Key**
  - Location: `site_settings.setting_key = 'google_maps_api_key'`
  - Used for public Google Maps JavaScript, Places autocomplete, geocoding, and directions.
  - Enable only the APIs the app uses: Maps JavaScript API, Geocoding API, Directions API, and Places API (New).
- **Google Places Scraper API Key**
  - Location: `site_settings.setting_key = 'google_places_scraper_api_key'`
  - Keep this blank unless scraper jobs are intentionally re-enabled. The public maps key is not used by the scraper.
- **Google Maps Map ID**
  - Location: `site_settings.setting_key = 'google_maps_map_id'`

## Security Best Practices

✅ **DO:**
- Keep API keys in the database only
- Restrict database access and backup encryption
- Restrict Google Maps key by domain/IP in Google Cloud Console

❌ **DON'T:**
- Hardcode API keys in JS/PHP files
- Share API keys in emails or chat
- Use the same keys for development and production

