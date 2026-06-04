<?php

class TermsController {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function getActiveTerms() {
        try {
            $stmt = $this->conn->prepare("SELECT id, version_number, effective_date FROM terms_versions WHERE is_active = TRUE ORDER BY id DESC LIMIT 1");
            $stmt->execute();
            $version = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$version) {
                http_response_code(404);
                return ['success' => false, 'message' => 'No active terms found'];
            }

            $stmt = $this->conn->prepare("SELECT section_title as title, section_content as content FROM terms_content WHERE version_id = ? ORDER BY display_order ASC");
            $stmt->execute([$version['id']]);
            $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Structure to match frontend requirements
            $formattedSections = array_map(function($section, $index) {
                return [
                    'id' => (string)($index + 1),
                    'title' => $section['title'],
                    'content' => $section['content']
                ];
            }, $sections, array_keys($sections));

            return [
                'success' => true,
                'data' => [
                    'version' => [
                        'id' => $version['id'],
                        'version_number' => $version['version_number'],
                        'effective_date' => $version['effective_date']
                    ],
                    'sections' => $formattedSections
                ]
            ];

        } catch (Exception $e) {
            http_response_code(500);
            return ['success' => false, 'message' => 'Failed to fetch active terms'];
        }
    }

    public function acceptTerms($tenantId, $versionId, $ipAddress = null, $deviceInfo = null) {
        try {
            $stmt = $this->conn->prepare("INSERT INTO terms_acceptance_logs (tenant_id, version_id, ip_address, device_info) VALUES (?, ?, ?, ?)");
            $stmt->execute([$tenantId, $versionId, $ipAddress, $deviceInfo]);

            return ['success' => true, 'message' => 'Terms accepted successfully'];
        } catch (Exception $e) {
            http_response_code(500);
            return ['success' => false, 'message' => 'Failed to record terms acceptance'];
        }
    }

    public function checkAcceptance($tenantId) {
        try {
            $stmt = $this->conn->prepare("SELECT id, version_number FROM terms_versions WHERE is_active = TRUE ORDER BY id DESC LIMIT 1");
            $stmt->execute();
            $activeVersion = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$activeVersion) {
                return ['success' => true, 'accepted' => true]; // Allow if no terms exist
            }

            $stmt = $this->conn->prepare("SELECT id FROM terms_acceptance_logs WHERE tenant_id = ? AND version_id = ?");
            $stmt->execute([$tenantId, $activeVersion['id']]);
            
            if ($stmt->rowCount() > 0) {
                return ['success' => true, 'accepted' => true];
            } else {
                return [
                    'success' => true, 
                    'accepted' => false,
                    'active_version' => $activeVersion['version_number']
                ];
            }

        } catch (Exception $e) {
            http_response_code(500);
            return ['success' => false, 'message' => 'Failed to check acceptance'];
        }
    }
}
