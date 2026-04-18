<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// Headers - set these BEFORE any output
header('Content-Type: application/json; charset=utf-8');
// CORS Configuration - Must use specific origin when credentials are enabled
// Cannot use '*' when Access-Control-Allow-Credentials is true
$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? null;

// Determine the origin to use for CORS header
$originToUse = null;

// Priority 1: Use the Origin header from the request (most reliable)
if ($requestOrigin) {
    $parsedOrigin = parse_url($requestOrigin);
    if ($parsedOrigin) {
        $originHost = $parsedOrigin['host'] ?? '';
        // Allow localhost and 127.0.0.1 for local development
        if (in_array($originHost, ['localhost', '127.0.0.1'])) {
            $originToUse = $requestOrigin; // Use exact origin from request
        }
    }
}

// Priority 2: If no Origin header, try to get from Referer
if (!$originToUse && !empty($_SERVER['HTTP_REFERER'])) {
    $parsedUrl = parse_url($_SERVER['HTTP_REFERER']);
    if ($parsedUrl) {
        $scheme = $parsedUrl['scheme'] ?? 'http';
        $host = $parsedUrl['host'] ?? '';
        $port = !empty($parsedUrl['port']) ? ':' . $parsedUrl['port'] : '';
        if (in_array($host, ['localhost', '127.0.0.1'])) {
            $originToUse = $scheme . '://' . $host . $port;
        }
    }
}

// Priority 3: Construct from current request (fallback for same-origin requests)
if (!$originToUse) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
    $hostParts = explode(':', $host);
    $hostOnly = $hostParts[0];
    if (in_array($hostOnly, ['localhost', '127.0.0.1'])) {
        $originToUse = $scheme . '://' . $host;
    }
}

// Set CORS headers - MUST be specific origin, never '*' when credentials are enabled
if ($originToUse) {
    header("Access-Control-Allow-Origin: $originToUse");
} else {
    // Last resort: log error and use a default (shouldn't happen in normal operation)
    error_log("Admin Edit API CORS: Could not determine origin. Request origin: " . ($requestOrigin ?? 'none') . ", Referer: " . ($_SERVER['HTTP_REFERER'] ?? 'none'));
    // Default to localhost:8000 for local development
    header("Access-Control-Allow-Origin: http://127.0.0.1:8000");
}

header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cookie');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Include your existing config
require_once 'admin-config.php';
require_once __DIR__ . '/../includes/runtime-site-config.php';

try {
    $pdo = getDatabase(); // Use your existing database function
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// Get action from POST or GET
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if (empty($action)) {
    echo json_encode(['success' => false, 'message' => 'No action specified']);
    exit();
}

try {
    switch ($action) {
        // Car operations
        case 'get_car':
            getCar($pdo);
            break;
        case 'update_car':
            updateCar($pdo);
            break;
        case 'get_makes_models':
            getMakesAndModels($pdo);
            break;
        case 'get_models':
            getModels($pdo);
            break;
        case 'get_car_listing_images':
            getCarImages($pdo);
            break;
        case 'set_primary_image':
            setPrimaryImage($pdo);
            break;
        case 'delete_car_image':
            deleteCarImage($pdo);
            break;
        
        // Garage operations
        case 'get_garage':
            getGarage($pdo);
            break;
        case 'update_garage':
            updateGarage($pdo);
            break;
        case 'suspend_garage':
            suspendGarage($pdo);
            break;
        
        // Dealer operations
        case 'get_dealer':
            getDealer($pdo);
            break;
        case 'update_dealer':
            updateDealer($pdo);
            break;
        case 'suspend_dealer':
            suspendDealer($pdo);
            break;
        
        // Car Hire operations
        case 'get_car_hire':
            getCarHire($pdo);
            break;
        case 'get_car_hire_fleet_admin':
            getCarHireFleetAdmin($pdo);
            break;
        case 'update_car_hire':
            updateCarHire($pdo);
            break;
        case 'suspend_car_hire':
            suspendCarHire($pdo);
            break;
        
        // Make operations
        case 'get_make':
            getMake($pdo);
            break;
        case 'update_make':
            updateMake($pdo);
            break;
        case 'delete_make':
            deleteMake($pdo);
            break;
        case 'get_makes':
            getMakes($pdo);
            break;
        
        // Model operations
        case 'get_model':
            getModel($pdo);
            break;
        case 'update_model':
            updateModel($pdo);
            break;
        case 'delete_model':
            deleteModel($pdo);
            break;
        
        // Location operations
        case 'get_location':
            getLocation($pdo);
            break;
        case 'get_locations':
            getLocations($pdo);
            break;
        case 'update_location':
            updateLocation($pdo);
            break;
        case 'delete_location':
            deleteLocation($pdo);
            break;
        
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action: ' . $action]);
            break;
    }
} catch (Exception $e) {
    error_log("Admin Edit API Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage(),
        'error' => $e->getMessage()
    ]);
}

// ============================================================================
// CAR FUNCTIONS
// ============================================================================

function getCar($pdo) {
    $carId = $_POST['listing_id'] ?? 0;
    
    try {
        $stmt = $pdo->prepare("
            SELECT c.*, u.full_name as owner_name, u.email as owner_email, u.phone as owner_phone, u.user_type
            FROM car_listings c
            LEFT JOIN users u ON c.user_id = u.id
            WHERE c.id = ?
        ");
        $stmt->execute([$carId]);
        $car = $stmt->fetch();
        
        if ($car) {
            echo json_encode(['success' => true, 'car' => $car]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Car not found']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}


function updateCar($pdo) {
    $carId = $_POST['listing_id'] ?? 0;
    
    try {
        // Basic car data
        $updateData = [
            'title' => $_POST['title'] ?? '',
            'price' => $_POST['price'] ?? 0,
            'make_id' => $_POST['make_id'] ?? 0,
            'model_id' => $_POST['model_id'] ?? 0,
            'year' => $_POST['year'] ?? 0,
            'mileage' => $_POST['mileage'] ?: null,
            'fuel_type' => $_POST['fuel_type'] ?: null,
            'transmission' => $_POST['transmission'] ?: null,
            'description' => $_POST['description'] ?: null,
            'location' => $_POST['location'] ?: null,
            'condition' => $_POST['condition'] ?: null,
            'status' => $_POST['status'] ?? 'active',
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        // Build the update query
        $setParts = [];
        $params = [];
        foreach ($updateData as $key => $value) {
            $setParts[] = "$key = ?";
            $params[] = $value;
        }
        $params[] = $carId;
        
        $sql = "UPDATE car_listings SET " . implode(', ', $setParts) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        // Handle new image uploads
        if (!empty($_FILES['new_images'])) {
            handleImageUploads($pdo, $carId);
        }
        
        echo json_encode(['success' => true, 'message' => 'Car updated successfully']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Update failed: ' . $e->getMessage()]);
    }
}

function handleImageUploads($pdo, $carId) {
    $uploadDir = '../uploads/cars/';
    
    // Create directory if it doesn't exist
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    // Handle single file or array of files
    if (isset($_FILES['new_images']['name'])) {
        if (is_array($_FILES['new_images']['name'])) {
            // Multiple files
            foreach ($_FILES['new_images']['tmp_name'] as $key => $tmpName) {
                if ($_FILES['new_images']['error'][$key] === UPLOAD_ERR_OK) {
                    $fileName = uniqid() . '_' . basename($_FILES['new_images']['name'][$key]);
                    $filePath = $uploadDir . $fileName;
                    
                    if (move_uploaded_file($tmpName, $filePath)) {
                        // Insert image record into database
                        $stmt = $pdo->prepare("
                            INSERT INTO car_listing_images (listing_id, filename, file_path, is_primary, uploaded_at)
                            VALUES (?, ?, ?, 0, NOW())
                        ");
                        $stmt->execute([$carId, $fileName]);
                    }
                }
            }
        } else {
            // Single file
            if ($_FILES['new_images']['error'] === UPLOAD_ERR_OK) {
                $fileName = uniqid() . '_' . basename($_FILES['new_images']['name']);
                $filePath = $uploadDir . $fileName;
                
                if (move_uploaded_file($_FILES['new_images']['tmp_name'], $filePath)) {
                    // Insert image record into database
                    $stmt = $pdo->prepare("
                        INSERT INTO car_listing_images (listing_id, filename, is_primary, created_at) 
                        VALUES (?, ?, 0, NOW())
                    ");
                    $stmt->execute([$carId, $fileName]);
                }
            }
        }
    }
}

function getMakesAndModels($pdo) {
    try {
        // Get all makes
        $makesStmt = $pdo->query("SELECT id, name FROM car_makes WHERE is_active = 1 ORDER BY name");
        $makes = $makesStmt->fetchAll();
        
        echo json_encode(['success' => true, 'makes' => $makes]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

function getModels($pdo) {
    $makeId = $_POST['make_id'] ?? 0;
    
    try {
        // Return unique model names (grouped by name to avoid duplicates)
        // Since we now have multiple rows per model (one per engine size variation),
        // we group by name and return the first model ID for each unique model name
        $stmt = $pdo->prepare("
            SELECT 
                MIN(id) as id, 
                name, 
                body_type, 
                MIN(year_start) as year_start, 
                MAX(year_end) as year_end,
                COUNT(*) as variation_count
            FROM car_models
            WHERE make_id = ? AND is_active = 1
            GROUP BY name, body_type
            ORDER BY name
        ");
        $stmt->execute([$makeId]);
        $models = $stmt->fetchAll();
        
        echo json_encode(['success' => true, 'models' => $models]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

function getCarImages($pdo) {
    $carId = $_POST['listing_id'] ?? 0;
    
    try {
        $stmt = $pdo->prepare("
            SELECT id, filename, is_primary 
            FROM car_listing_images 
            WHERE listing_id = ? 
            ORDER BY is_primary DESC, id ASC
        ");
        $stmt->execute([$carId]);
        $images = $stmt->fetchAll();
        
        // Convert to full URLs (adjust path as needed)
        foreach ($images as &$image) {
            $image['url'] = '/uploads/cars/' . $image['filename'];
        }
        
        echo json_encode(['success' => true, 'images' => $images]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

function setPrimaryImage($pdo) {
    $imageId = $_POST['image_id'] ?? 0;
    $carId = $_POST['listing_id'] ?? 0;
    
    try {
        // Start transaction
        $pdo->beginTransaction();
        
        // Reset all primary images for this car
        $resetStmt = $pdo->prepare("
            UPDATE car_listing_images 
            SET is_primary = 0 
            WHERE listing_id = ?
        ");
        $resetStmt->execute([$carId]);
        
        // Set the new primary image
        $setStmt = $pdo->prepare("
            UPDATE car_listing_images 
            SET is_primary = 1 
            WHERE id = ?
        ");
        $setStmt->execute([$imageId]);
        
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Primary image updated']);
    } catch (PDOException $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

function deleteCarImage($pdo) {
    $imageId = $_POST['image_id'] ?? 0;
    
    try {
        // First get the image info to delete the file
        $stmt = $pdo->prepare("SELECT filename FROM car_listing_images WHERE id = ?");
        $stmt->execute([$imageId]);
        $image = $stmt->fetch();
        
        if ($image) {
            // Delete the file
            $filePath = '../uploads/cars/' . $image['filename'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            
            // Delete the database record
            $deleteStmt = $pdo->prepare("DELETE FROM car_listing_images WHERE id = ?");
            $deleteStmt->execute([$imageId]);
            
            echo json_encode(['success' => true, 'message' => 'Image deleted successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Image not found']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

// ============================================================================
// GARAGE FUNCTIONS
// ============================================================================

function getGarage($pdo) {
    $garageId = $_POST['garage_id'] ?? 0;
    
    try {
        $stmt = $pdo->prepare("
            SELECT g.*
            FROM garages g
            WHERE g.id = ?
        ");
        $stmt->execute([$garageId]);
        $garage = $stmt->fetch();
        
        if ($garage) {
            echo json_encode(['success' => true, 'garage' => $garage]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Garage not found']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

function updateGarage($pdo) {
    $garageId = $_POST['garage_id'] ?? 0;
    
    try {
        $updateData = [
            'name' => $_POST['name'] ?? '',
            'owner_name' => $_POST['owner_name'] ?: null,
            'email' => $_POST['email'] ?: null,
            'phone' => $_POST['phone'] ?? '',
            'location' => $_POST['location'] ?? '',
            'specialization' => $_POST['specialization'] ?: null,
            'description' => $_POST['description'] ?: null,
            'services' => $_POST['services'] ?: null,
            'operating_hours' => $_POST['operating_hours'] ?: null,
            'rating' => isset($_POST['rating']) ? floatval($_POST['rating']) : null,
            'status' => $_POST['status'] ?? 'active',
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        // Build the update query
        $setParts = [];
        $params = [];
        foreach ($updateData as $key => $value) {
            $setParts[] = "$key = ?";
            $params[] = $value;
        }
        $params[] = $garageId;
        
        $sql = "UPDATE garages SET " . implode(', ', $setParts) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        echo json_encode(['success' => true, 'message' => 'Garage updated successfully']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Update failed: ' . $e->getMessage()]);
    }
}

function suspendGarage($pdo) {
    $garageId = $_POST['garage_id'] ?? 0;

    try {
        // Start transaction
        $pdo->beginTransaction();

        // Get user_id associated with this garage
        $stmt = $pdo->prepare("SELECT user_id FROM garages WHERE id = ?");
        $stmt->execute([$garageId]);
        $garage = $stmt->fetch(PDO::FETCH_ASSOC);

        // Suspend garage
        $stmt = $pdo->prepare("UPDATE garages SET status = 'suspended', updated_at = NOW() WHERE id = ?");
        $stmt->execute([$garageId]);

        // Also suspend the associated user account if exists
        if ($garage && $garage['user_id']) {
            $stmt = $pdo->prepare("UPDATE users SET status = 'suspended', updated_at = NOW() WHERE id = ?");
            $stmt->execute([$garage['user_id']]);
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Garage and associated user account suspended successfully']);
    } catch (PDOException $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Suspend failed: ' . $e->getMessage()]);
    }
}

// ============================================================================
// DEALER FUNCTIONS
// ============================================================================

function getDealer($pdo) {
    $dealerId = $_POST['dealer_id'] ?? 0;

    try {
        $stmt = $pdo->prepare("
            SELECT d.*
            FROM car_dealers d
            WHERE d.id = ?
        ");
        $stmt->execute([$dealerId]);
        $dealer = $stmt->fetch();

        if ($dealer) {
            echo json_encode(['success' => true, 'dealer' => $dealer]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Dealer not found']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

function updateDealer($pdo) {
    $dealerId = $_POST['dealer_id'] ?? 0;
    
    try {
        $updateData = [
            'business_name' => $_POST['business_name'] ?? '',
            'owner_name' => $_POST['owner_name'] ?: null,
            'email' => $_POST['email'] ?: null,
            'phone' => $_POST['phone'] ?? '',
            'location' => $_POST['location'] ?? '',
            'dealer_type' => $_POST['dealer_type'] ?: null,
            'years_in_business' => isset($_POST['years_in_business']) ? intval($_POST['years_in_business']) : null,
            'description' => $_POST['description'] ?: null,
            'specializations' => $_POST['specializations'] ?: null,
            'rating' => isset($_POST['rating']) ? floatval($_POST['rating']) : null,
            'status' => $_POST['status'] ?? 'active',
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        // Build the update query
        $setParts = [];
        $params = [];
        foreach ($updateData as $key => $value) {
            $setParts[] = "$key = ?";
            $params[] = $value;
        }
        $params[] = $dealerId;
        
        $sql = "UPDATE car_dealers SET " . implode(', ', $setParts) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        echo json_encode(['success' => true, 'message' => 'Dealer updated successfully']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Update failed: ' . $e->getMessage()]);
    }
}

function suspendDealer($pdo) {
    $dealerId = $_POST['dealer_id'] ?? 0;

    try {
        // Start transaction
        $pdo->beginTransaction();

        // Get user_id associated with this dealer
        $stmt = $pdo->prepare("SELECT user_id FROM car_dealers WHERE id = ?");
        $stmt->execute([$dealerId]);
        $dealer = $stmt->fetch(PDO::FETCH_ASSOC);

        // Suspend dealer
        $stmt = $pdo->prepare("UPDATE car_dealers SET status = 'suspended', updated_at = NOW() WHERE id = ?");
        $stmt->execute([$dealerId]);

        // Also suspend the associated user account if exists
        if ($dealer && $dealer['user_id']) {
            $stmt = $pdo->prepare("UPDATE users SET status = 'suspended', updated_at = NOW() WHERE id = ?");
            $stmt->execute([$dealer['user_id']]);
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Dealer and associated user account suspended successfully']);
    } catch (PDOException $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Suspend failed: ' . $e->getMessage()]);
    }
}

// ============================================================================
// CAR HIRE FUNCTIONS
// ============================================================================

function getCarHire($pdo) {
    $carHireId = $_POST['car_hire_id'] ?? 0;

    try {
        $stmt = $pdo->prepare("
            SELECT ch.*
            FROM car_hire_companies ch
            WHERE ch.id = ?
        ");
        $stmt->execute([$carHireId]);
        $carHire = $stmt->fetch();

        if ($carHire) {
            echo json_encode(['success' => true, 'car_hire' => $carHire]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Car hire not found']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

function getCarHireFleetAdmin($pdo) {
    $carHireId = intval($_POST['car_hire_id'] ?? 0);
    if (!$carHireId) {
        echo json_encode(['success' => false, 'message' => 'Missing car_hire_id']);
        return;
    }
    try {
        $stmt = $pdo->prepare("
            SELECT f.id, f.vehicle_name, f.vehicle_category, f.status,
                   f.seats, f.cargo_capacity, f.event_suitable,
                   f.daily_rate, f.registration_number, f.fuel_type, f.transmission,
                   f.image, f.created_at
            FROM car_hire_fleet f
            WHERE f.company_id = ?
            ORDER BY f.vehicle_category, f.created_at DESC
        ");
        $stmt->execute([$carHireId]);
        $fleet = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Count by category
        $counts = ['car' => 0, 'van' => 0, 'truck' => 0];
        foreach ($fleet as $v) {
            $cat = $v['vehicle_category'] ?? 'car';
            if (isset($counts[$cat])) $counts[$cat]++;
        }
        echo json_encode(['success' => true, 'fleet' => $fleet, 'counts' => $counts]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

function updateCarHire($pdo) {
    $carHireId = $_POST['car_hire_id'] ?? 0;
    
    try {
        $pdo->beginTransaction();

        $updateData = [
            'business_name' => $_POST['business_name'] ?? '',
            'owner_name' => $_POST['owner_name'] ?: null,
            'email' => $_POST['email'] ?: null,
            'phone' => $_POST['phone'] ?? '',
            'whatsapp' => $_POST['whatsapp'] ?: null,
            'address' => $_POST['address'] ?: null,
            'location_id' => !empty($_POST['location_id']) ? (int)$_POST['location_id'] : null,
            'website' => $_POST['website'] ?: null,
            'years_established' => !empty($_POST['years_established']) ? (int)$_POST['years_established'] : null,
            'business_hours' => $_POST['business_hours'] ?: null,
            'daily_rate_from' => isset($_POST['daily_rate_from']) ? floatval($_POST['daily_rate_from']) : null,
            'weekly_rate_from' => isset($_POST['weekly_rate_from']) ? floatval($_POST['weekly_rate_from']) : null,
            'monthly_rate_from' => isset($_POST['monthly_rate_from']) ? floatval($_POST['monthly_rate_from']) : null,
            'description' => $_POST['description'] ?: null,
            'status' => $_POST['status'] ?? 'active',
            'updated_at' => date('Y-m-d H:i:s')
        ];

        // Handle hire_category
        $hireCategory = $_POST['hire_category'] ?? '';
        if ($hireCategory && in_array($hireCategory, ['standard', 'events', 'vans_trucks', 'all'])) {
            $updateData['hire_category'] = $hireCategory;
        }

        // Handle event_types (JSON from form)
        if (isset($_POST['event_types'])) {
            $eventTypes = $_POST['event_types'];
            if (is_string($eventTypes)) {
                $decoded = json_decode($eventTypes, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $updateData['event_types'] = json_encode($decoded);
                } else {
                    $updateData['event_types'] = json_encode(array_map('trim', explode(',', $eventTypes)));
                }
            } elseif (is_array($eventTypes)) {
                $updateData['event_types'] = json_encode($eventTypes);
            }
        }
        
        // Build the update query
        $setParts = [];
        $params = [];
        foreach ($updateData as $key => $value) {
            $setParts[] = "$key = ?";
            $params[] = $value;
        }
        $params[] = $carHireId;
        
        $sql = "UPDATE car_hire_companies SET " . implode(', ', $setParts) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        // Keep linked user account status aligned with business status changes.
        $stmt = $pdo->prepare("SELECT user_id FROM car_hire_companies WHERE id = ?");
        $stmt->execute([$carHireId]);
        $carHire = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($carHire && !empty($carHire['user_id'])) {
            $userStatus = null;
            if ($updateData['status'] === 'active') {
                $userStatus = 'active';
            } elseif ($updateData['status'] === 'pending_approval') {
                $userStatus = 'pending';
            } elseif ($updateData['status'] === 'suspended') {
                $userStatus = 'suspended';
            }

            if ($userStatus !== null) {
                $userStmt = $pdo->prepare("UPDATE users SET status = ?, updated_at = NOW() WHERE id = ?");
                $userStmt->execute([$userStatus, $carHire['user_id']]);
            }
        }

        $pdo->commit();

        echo json_encode(['success' => true, 'message' => 'Car hire updated successfully']);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'message' => 'Update failed: ' . $e->getMessage()]);
    }
}

function suspendCarHire($pdo) {
    $carHireId = $_POST['car_hire_id'] ?? 0;

    try {
        // Start transaction
        $pdo->beginTransaction();

        // Get user_id associated with this car hire company
        $stmt = $pdo->prepare("SELECT user_id FROM car_hire_companies WHERE id = ?");
        $stmt->execute([$carHireId]);
        $carHire = $stmt->fetch(PDO::FETCH_ASSOC);

        // Suspend car hire company
        $stmt = $pdo->prepare("UPDATE car_hire_companies SET status = 'suspended', updated_at = NOW() WHERE id = ?");
        $stmt->execute([$carHireId]);

        // Also suspend the associated user account if exists
        if ($carHire && $carHire['user_id']) {
            $stmt = $pdo->prepare("UPDATE users SET status = 'suspended', updated_at = NOW() WHERE id = ?");
            $stmt->execute([$carHire['user_id']]);
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Car hire and associated user account suspended successfully']);
    } catch (PDOException $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Suspend failed: ' . $e->getMessage()]);
    }
}

// ============================================================================
// MAKE FUNCTIONS
// ============================================================================

function getMake($pdo) {
    $makeId = $_POST['make_id'] ?? 0;
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM car_makes WHERE id = ?");
        $stmt->execute([$makeId]);
        $make = $stmt->fetch();
        
        if ($make) {
            echo json_encode(['success' => true, 'make' => $make]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Make not found']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

function getMakes($pdo) {
    try {
        $stmt = $pdo->query("SELECT id, name FROM car_makes WHERE is_active = 1 ORDER BY name");
        $makes = $stmt->fetchAll();
        
        echo json_encode(['success' => true, 'makes' => $makes]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

function updateMake($pdo) {
    $makeId = $_POST['make_id'] ?? 0;

    try {
        // Only include columns that actually exist in the car_makes table
        $updateData = [
            'name' => $_POST['name'] ?? '',
            'country' => $_POST['country'] ?: null,
            'is_active' => isset($_POST['is_active']) ? intval($_POST['is_active']) : 1,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        // Build the update query
        $setParts = [];
        $params = [];
        foreach ($updateData as $key => $value) {
            $setParts[] = "$key = ?";
            $params[] = $value;
        }
        $params[] = $makeId;

        $sql = "UPDATE car_makes SET " . implode(', ', $setParts) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        echo json_encode(['success' => true, 'message' => 'Make updated successfully']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Update failed: ' . $e->getMessage()]);
    }
}

function deleteMake($pdo) {
    $makeId = $_POST['make_id'] ?? 0;
    
    try {
        // Check if make has models
        $checkStmt = $pdo->prepare("SELECT COUNT(*) as model_count FROM car_models WHERE make_id = ?");
        $checkStmt->execute([$makeId]);
        $result = $checkStmt->fetch();
        
        if ($result['model_count'] > 0) {
            echo json_encode(['success' => false, 'message' => 'Cannot delete make. It has associated models.']);
            return;
        }
        
        $stmt = $pdo->prepare("DELETE FROM car_makes WHERE id = ?");
        $stmt->execute([$makeId]);
        
        echo json_encode(['success' => true, 'message' => 'Make deleted successfully']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Delete failed: ' . $e->getMessage()]);
    }
}

// ============================================================================
// MODEL FUNCTIONS
// ============================================================================

function getModel($pdo) {
    $modelId = $_POST['model_id'] ?? 0;
    
    try {
        $stmt = $pdo->prepare("
            SELECT m.*, mk.name as make_name
            FROM car_models m
            LEFT JOIN car_makes mk ON m.make_id = mk.id
            WHERE m.id = ?
        ");
        $stmt->execute([$modelId]);
        $model = $stmt->fetch();
        
        if ($model) {
            echo json_encode(['success' => true, 'model' => $model]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Model not found']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

function updateModel($pdo) {
    $modelId = $_POST['model_id'] ?? 0;

    try {
        // Build update data array with all fields
        $updateData = [
            'name' => $_POST['name'] ?? '',
            'make_id' => $_POST['make_id'] ?? 0,
            'body_type' => $_POST['body_type'] ?: null,
            'description' => $_POST['description'] ?: null,
            'is_active' => isset($_POST['is_active']) ? intval($_POST['is_active']) : 0,
            'year_start' => !empty($_POST['year_start']) ? (int)$_POST['year_start'] : null,
            'year_end' => !empty($_POST['year_end']) ? (int)$_POST['year_end'] : null,
            'fuel_tank_capacity_liters' => !empty($_POST['fuel_tank_capacity_liters']) ? (float)$_POST['fuel_tank_capacity_liters'] : null,
            'engine_size_liters' => !empty($_POST['engine_size_liters']) ? (float)$_POST['engine_size_liters'] : null,
            'engine_cylinders' => !empty($_POST['engine_cylinders']) ? (int)$_POST['engine_cylinders'] : null,
            'fuel_consumption_urban_l100km' => !empty($_POST['fuel_consumption_urban_l100km']) ? (float)$_POST['fuel_consumption_urban_l100km'] : null,
            'fuel_consumption_highway_l100km' => !empty($_POST['fuel_consumption_highway_l100km']) ? (float)$_POST['fuel_consumption_highway_l100km'] : null,
            'fuel_consumption_combined_l100km' => !empty($_POST['fuel_consumption_combined_l100km']) ? (float)$_POST['fuel_consumption_combined_l100km'] : null,
            'fuel_type' => $_POST['fuel_type'] ?: null,
            'transmission_type' => $_POST['transmission_type'] ?: null,
            'horsepower_hp' => !empty($_POST['horsepower_hp']) ? (int)$_POST['horsepower_hp'] : null,
            'torque_nm' => !empty($_POST['torque_nm']) ? (int)$_POST['torque_nm'] : null,
            'seating_capacity' => !empty($_POST['seating_capacity']) ? (int)$_POST['seating_capacity'] : null,
            'doors' => !empty($_POST['doors']) ? (int)$_POST['doors'] : null,
            'weight_kg' => !empty($_POST['weight_kg']) ? (int)$_POST['weight_kg'] : null,
            'drive_type' => $_POST['drive_type'] ?: null,
            'co2_emissions_gkm' => !empty($_POST['co2_emissions_gkm']) ? (int)$_POST['co2_emissions_gkm'] : null,
            'length_mm' => !empty($_POST['length_mm']) ? (int)$_POST['length_mm'] : null,
            'width_mm' => !empty($_POST['width_mm']) ? (int)$_POST['width_mm'] : null,
            'height_mm' => !empty($_POST['height_mm']) ? (int)$_POST['height_mm'] : null,
            'wheelbase_mm' => !empty($_POST['wheelbase_mm']) ? (int)$_POST['wheelbase_mm'] : null,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        // Build the update query
        $setParts = [];
        $params = [];
        foreach ($updateData as $key => $value) {
            $setParts[] = "$key = ?";
            $params[] = $value;
        }
        $params[] = $modelId;

        $sql = "UPDATE car_models SET " . implode(', ', $setParts) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        echo json_encode(['success' => true, 'message' => 'Model updated successfully']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Update failed: ' . $e->getMessage()]);
    }
}

function deleteModel($pdo) {
    $modelId = $_POST['model_id'] ?? 0;
    
    try {
        // Check if model has cars
        $checkStmt = $pdo->prepare("SELECT COUNT(*) as car_count FROM car_listings WHERE model_id = ?");
        $checkStmt->execute([$modelId]);
        $result = $checkStmt->fetch();
        
        if ($result['car_count'] > 0) {
            echo json_encode(['success' => false, 'message' => 'Cannot delete model. It has associated cars.']);
            return;
        }
        
        $stmt = $pdo->prepare("DELETE FROM car_models WHERE id = ?");
        $stmt->execute([$modelId]);
        
        echo json_encode(['success' => true, 'message' => 'Model deleted successfully']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Delete failed: ' . $e->getMessage()]);
    }
}

// ============================================================================
// LOCATION FUNCTIONS
// ============================================================================

function getLocation($pdo) {
    $locationId = $_POST['location_id'] ?? 0;
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM locations WHERE id = ?");
        $stmt->execute([$locationId]);
        $location = $stmt->fetch();
        
        if ($location) {
            echo json_encode(['success' => true, 'location' => $location]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Location not found']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

function getLocations($pdo) {
    try {
        $where = ["is_active = 1"];
        $params = [];
        
        if (!empty($_GET['region'])) {
            $where[] = "region = ?";
            $params[] = $_GET['region'];
        }
        
        if (!empty($_GET['search'])) {
            $where[] = "(name LIKE ? OR region LIKE ? OR district LIKE ?)";
            $searchTerm = "%" . $_GET['search'] . "%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        $whereClause = implode(' AND ', $where);
        
        $sql = "SELECT * FROM locations WHERE {$whereClause} ORDER BY region, name";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'locations' => $locations]);
        exit();
    } catch (Exception $e) {
        error_log("getLocations error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to load locations']);
        exit();
    }
}

function updateLocation($pdo) {
    $locationId = $_POST['location_id'] ?? 0;
    
    try {
        $siteConfig = motorlink_get_site_runtime_config($pdo, [
            'include_private' => true,
            'runtime_base_url' => motorlink_get_runtime_origin_fallback()
        ]);
        $defaultCountry = trim((string)($siteConfig['country_name'] ?? ''));

        $updateData = [
            'name' => $_POST['name'] ?? '',
            'region' => $_POST['region'] ?? '',
            'district' => $_POST['district'] ?: null,
            'country' => $_POST['country'] ?: ($defaultCountry !== '' ? $defaultCountry : null),
            'latitude' => $_POST['latitude'] ?: null,
            'longitude' => $_POST['longitude'] ?: null,
            'is_active' => isset($_POST['is_active']) ? intval($_POST['is_active']) : 0,
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        // Build the update query
        $setParts = [];
        $params = [];
        foreach ($updateData as $key => $value) {
            $setParts[] = "$key = ?";
            $params[] = $value;
        }
        $params[] = $locationId;
        
        $sql = "UPDATE locations SET " . implode(', ', $setParts) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        echo json_encode(['success' => true, 'message' => 'Location updated successfully']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Update failed: ' . $e->getMessage()]);
    }
}

function deleteLocation($pdo) {
    $locationId = $_POST['location_id'] ?? 0;
    
    try {
        // Check if location is used
        $checkStmt = $pdo->prepare("
            SELECT
                (SELECT COUNT(*) FROM car_listings WHERE location_id = ?) +
                (SELECT COUNT(*) FROM garages WHERE location_id = ?) +
                (SELECT COUNT(*) FROM car_dealers WHERE location_id = ?) +
                (SELECT COUNT(*) FROM car_hire_companies WHERE location_id = ?) as usage_count
        ");
        $checkStmt->execute([$locationId, $locationId, $locationId, $locationId]);
        $result = $checkStmt->fetch();
        
        if ($result['usage_count'] > 0) {
            echo json_encode(['success' => false, 'message' => 'Cannot delete location. It is being used by other records.']);
            return;
        }
        
        $stmt = $pdo->prepare("DELETE FROM locations WHERE id = ?");
        $stmt->execute([$locationId]);
        
        echo json_encode(['success' => true, 'message' => 'Location deleted successfully']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Delete failed: ' . $e->getMessage()]);
    }
}
?>
