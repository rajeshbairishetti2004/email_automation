<?php
require_once 'db_config.php';

class DataParser {
    private $conn;
    
    public function __construct($connection) {
        $this->conn = $connection;
    }
    
    // Parse client data from various formats
    ////
    public function parseClientData($data, $format = 'csv') {
        $clients = [];
        
        if ($format === 'csv') {
            $lines = explode("\n", $data);
            $headers = str_getcsv(array_shift($lines));
            
            foreach ($lines as $line) {
                if (trim($line)) {
                    $row = str_getcsv($line);
                    $client = [];
                    foreach ($headers as $index => $header) {
                        if (isset($row[$index])) {
                            $client[$header] = $row[$index];
                        }
                    }
                    $clients[] = $client;
                }
            }
        }
        
        return $clients;
    }
    
    // Parse scheme data
    public function parseSchemeData($data, $client_id) {
        $schemes = [];
        $lines = explode("\n", $data);
        
        // Skip header if present
        $first_line = str_getcsv($lines[0]);
        if (isset($first_line[0]) && strpos($first_line[0], 'scheme_name') !== false) {
            array_shift($lines);
        }
        
        foreach ($lines as $line) {
            if (trim($line)) {
                $row = str_getcsv($line);
                if (count($row) >= 3) {
                    $scheme = [
                        'client_id' => $client_id,
                        'scheme_name' => trim($row[0]),
                        'sip_swp' => floatval($row[1]),
                        'current_value' => floatval($row[2]),
                        'action_step' => isset($row[3]) ? trim($row[3]) : 'Continue',
                        'recommended_scheme' => isset($row[4]) ? trim($row[4]) : '',
                        'recommended_amount' => isset($row[5]) ? trim($row[5]) : '0'
                    ];
                    $schemes[] = $scheme;
                }
            }
        }
        
        return $schemes;
    }
    
    // Parse goal data
    public function parseGoalData($data, $client_id) {
        $goals = [];
        $lines = explode("\n", $data);
        
        // Skip header if present
        $first_line = str_getcsv($lines[0]);
        if (isset($first_line[0]) && strpos($first_line[0], 'goal') !== false) {
            array_shift($lines);
        }
        
        foreach ($lines as $line) {
            if (trim($line)) {
                $row = str_getcsv($line);
                if (count($row) >= 4) {
                    $goal = [
                        'client_id' => $client_id,
                        'goal' => trim($row[0]),
                        'goal_date' => trim($row[1]),
                        'current_value' => floatval($row[2]),
                        'target_amount' => floatval($row[3]),
                        'running_sip' => isset($row[4]) ? floatval($row[4]) : 0,
                        'projected' => isset($row[5]) ? floatval($row[5]) : 0,
                        'completion' => isset($row[6]) ? floatval($row[6]) : 0,
                        'shortfall' => isset($row[7]) ? floatval($row[7]) : 0,
                        'status' => isset($row[8]) ? trim($row[8]) : 'On Track'
                    ];
                    $goals[] = $goal;
                }
            }
        }
        
        return $goals;
    }
    
    // Calculate goal metrics
    public function calculateGoalMetrics($goal) {
        if ($goal['target_amount'] > 0) {
            $goal['completion'] = ($goal['current_value'] / $goal['target_amount']) * 100;
            $goal['shortfall'] = max(0, $goal['target_amount'] - $goal['projected']);
        } else {
            $goal['completion'] = 0;
            $goal['shortfall'] = 0;
        }
        
        // Determine status
        if ($goal['completion'] >= 100) {
            $goal['status'] = 'Achieved';
        } elseif ($goal['completion'] >= 75) {
            $goal['status'] = 'On Track';
        } elseif ($goal['completion'] >= 50) {
            $goal['status'] = 'Moderate';
        } else {
            $goal['status'] = 'Needs Attention';
        }
        
        return $goal;
    }
    
    // Parse allocation data
    public function parseAllocationData($data, $client_id) {
        $allocations = [];
        $lines = explode("\n", $data);
        
        foreach ($lines as $line) {
            if (trim($line)) {
                $row = str_getcsv($line);
                if (count($row) >= 2) {
                    $allocation = [
                        'client_id' => $client_id,
                        'asset' => trim($row[0]),
                        'share_pct' => floatval($row[1])
                    ];
                    $allocations[] = $allocation;
                }
            }
        }
        
        return $allocations;
    }
    
    // Validate data before insertion
    public function validateData($data, $type) {
        $errors = [];
        
        switch ($type) {
            case 'goal':
                if (empty($data['goal'])) {
                    $errors[] = "Goal name is required";
                }
                if (empty($data['goal_date'])) {
                    $errors[] = "Goal date is required";
                }
                if ($data['target_amount'] <= 0) {
                    $errors[] = "Target amount must be positive";
                }
                break;
                
            case 'scheme':
                if (empty($data['scheme_name'])) {
                    $errors[] = "Scheme name is required";
                }
                if ($data['current_value'] < 0) {
                    $errors[] = "Current value cannot be negative";
                }
                break;
                
            case 'allocation':
                if (empty($data['asset'])) {
                    $errors[] = "Asset class is required";
                }
                if ($data['share_pct'] < 0 || $data['share_pct'] > 100) {
                    $errors[] = "Share percentage must be between 0 and 100";
                }
                break;
        }
        
        return $errors;
    }
}

// Create global parser instance
$parser = new DataParser($conn);
?>
