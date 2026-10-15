<?php
require_once __DIR__ . '/../helpers/ResponseHelper.php';

class DormitoryController {
    private $db;

    public function __construct($dbConnection) {
        $this->db = $dbConnection;
    }

    /**
     * Get dormitory business information
     * Open to both tenants and landlords
     */
    public function getBusinessInfo() {
        try {
            $stmt = $this->db->prepare("SELECT * FROM dormitory_business_info ORDER BY id ASC LIMIT 1");
            $stmt->execute();
            $info = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$info) {
                // Return clean empty structure with explicit nulls - NO fake data
                $info = [
                    'id' => null,
                    'registered_business_name' => null,
                    'property_name' => null,
                    'business_address' => null,
                    'owner_operator_name' => null,
                    'registration_type' => null,
                    'registration_number' => null,
                    'contact_phone' => null,
                    'contact_email' => null,
                    'date_established' => null,
                    'tin_number' => null,
                    'notes' => null,
                    'updated_at' => null
                ];
            }

            ResponseHelper::success($info);
        } catch (Throwable $e) {
            ResponseHelper::error("Failed to retrieve dormitory business information: " . $e->getMessage(), 500);
        }
    }

    /**
     * Update dormitory business information
     * Strictly restricted to authorized Landlord administrator
     */
    public function updateBusinessInfo($authenticatedUser, $data) {
        if (($authenticatedUser['role'] ?? '') !== 'landlord') {
            ResponseHelper::error("Unauthorized. Only authorized landlords can modify business information.", 403);
            return;
        }

        try {
            // Trim and sanitize inputs
            $businessName = isset($data['registered_business_name']) ? trim($data['registered_business_name']) : null;
            $propertyName = isset($data['property_name']) ? trim($data['property_name']) : null;
            $address = isset($data['business_address']) ? trim($data['business_address']) : null;
            $ownerOperator = isset($data['owner_operator_name']) ? trim($data['owner_operator_name']) : null;
            $regType = isset($data['registration_type']) ? trim($data['registration_type']) : null;
            $regNumber = isset($data['registration_number']) ? trim($data['registration_number']) : null;
            $phone = isset($data['contact_phone']) ? trim($data['contact_phone']) : null;
            $email = isset($data['contact_email']) ? trim($data['contact_email']) : null;
            $dateEstablished = !empty($data['date_established']) ? trim($data['date_established']) : null;
            $tinNumber = isset($data['tin_number']) ? trim($data['tin_number']) : null;
            $notes = isset($data['notes']) ? trim($data['notes']) : null;
            $userId = $authenticatedUser['id'] ?? null;

            // Ensure single row (ID 1) - completely avoids duplicate records
            $stmt = $this->db->prepare("
                INSERT INTO dormitory_business_info (
                    id, 
                    registered_business_name, 
                    property_name, 
                    business_address, 
                    owner_operator_name, 
                    registration_type, 
                    registration_number, 
                    contact_phone, 
                    contact_email, 
                    date_established, 
                    tin_number, 
                    notes, 
                    updated_by, 
                    updated_at
                ) VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                    registered_business_name = VALUES(registered_business_name),
                    property_name = VALUES(property_name),
                    business_address = VALUES(business_address),
                    owner_operator_name = VALUES(owner_operator_name),
                    registration_type = VALUES(registration_type),
                    registration_number = VALUES(registration_number),
                    contact_phone = VALUES(contact_phone),
                    contact_email = VALUES(contact_email),
                    date_established = VALUES(date_established),
                    tin_number = VALUES(tin_number),
                    notes = VALUES(notes),
                    updated_by = VALUES(updated_by),
                    updated_at = NOW()
            ");

            $stmt->execute([
                $businessName,
                $propertyName,
                $address,
                $ownerOperator,
                $regType,
                $regNumber,
                $phone,
                $email,
                $dateEstablished,
                $tinNumber,
                $notes,
                $userId
            ]);

            // Log activity
            try {
                $logStmt = $this->db->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, 'UPDATE_DORMITORY_INFO', ?)");
                $logStmt->execute([$userId, "Updated dormitory business profile"]);
            } catch (Throwable $ignore) {}

            // Return updated row
            $fetchStmt = $this->db->prepare("SELECT * FROM dormitory_business_info WHERE id = 1");
            $fetchStmt->execute();
            $updated = $fetchStmt->fetch(PDO::FETCH_ASSOC);

            ResponseHelper::success($updated, "Dormitory business information updated successfully.");
        } catch (Throwable $e) {
            ResponseHelper::error("Failed to update dormitory business information: " . $e->getMessage(), 500);
        }
    }

    /**
     * Retrieve all uploaded documents
     * Tenants only receive documents flagged as public and get calculated verification status.
     */
    public function getDocuments($authenticatedUser) {
        try {
            $isLandlord = ($authenticatedUser['role'] ?? '') === 'landlord';

            if ($isLandlord) {
                $stmt = $this->db->prepare("SELECT * FROM dormitory_documents ORDER BY created_at DESC, id DESC");
                $stmt->execute();
            } else {
                $stmt = $this->db->prepare("SELECT id, document_name, document_type, issuing_authority, permit_number, issue_date, expiration_date, file_url, file_name, file_type, file_size_kb, verification_status, verified_at, updated_at FROM dormitory_documents WHERE is_public_for_tenants = 1 ORDER BY created_at DESC, id DESC");
                $stmt->execute();
            }

            $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $today = date('Y-m-d');

            // Compute dynamic status and format
            $computedDocs = array_map(function($doc) use ($today) {
                $expDate = $doc['expiration_date'] ? trim($doc['expiration_date']) : null;
                $isExpired = false;
                if ($expDate && $expDate !== '0000-00-00' && strtotime($expDate) < strtotime($today)) {
                    $isExpired = true;
                }

                $rawStatus = $doc['verification_status'] ?? 'pending_verification';
                if ($isExpired) {
                    $effectiveStatus = 'expired';
                    $statusLabel = 'Expired';
                } elseif ($rawStatus === 'verified') {
                    $effectiveStatus = 'verified';
                    $statusLabel = 'Valid';
                } else {
                    $effectiveStatus = 'pending_verification';
                    $statusLabel = 'Pending Verification';
                }

                $doc['effective_status'] = $effectiveStatus;
                $doc['status_label'] = $statusLabel;
                $doc['is_expired'] = $isExpired;

                return $doc;
            }, $docs);

            ResponseHelper::success($computedDocs);
        } catch (Throwable $e) {
            ResponseHelper::error("Failed to fetch dormitory documents: " . $e->getMessage(), 500);
        }
    }

    /**
     * Upload or update a document
     * Landlord only
     */
    public function saveDocument($authenticatedUser, $data) {
        if (($authenticatedUser['role'] ?? '') !== 'landlord') {
            ResponseHelper::error("Unauthorized. Only authorized landlords can upload or modify documents.", 403);
            return;
        }

        try {
            $docId = !empty($data['id']) ? (int)$data['id'] : null;
            $name = isset($data['document_name']) ? trim($data['document_name']) : '';
            $type = isset($data['document_type']) ? trim($data['document_type']) : '';
            $authority = isset($data['issuing_authority']) ? trim($data['issuing_authority']) : null;
            $permitNumber = isset($data['permit_number']) ? trim($data['permit_number']) : null;
            $issueDate = !empty($data['issue_date']) ? trim($data['issue_date']) : null;
            $expDate = !empty($data['expiration_date']) ? trim($data['expiration_date']) : null;
            $fileUrl = isset($data['file_url']) ? trim($data['file_url']) : '';
            $fileName = isset($data['file_name']) ? trim($data['file_name']) : null;
            $fileType = isset($data['file_type']) ? trim($data['file_type']) : 'image/jpeg';
            $fileSizeKb = !empty($data['file_size_kb']) ? (int)$data['file_size_kb'] : null;
            $isPublic = isset($data['is_public_for_tenants']) ? ($data['is_public_for_tenants'] ? 1 : 0) : 1;
            $verStatus = isset($data['verification_status']) && in_array($data['verification_status'], ['verified', 'pending_verification', 'expired']) 
                ? $data['verification_status'] 
                : 'pending_verification';
            $notes = isset($data['verification_notes']) ? trim($data['verification_notes']) : null;
            $userId = $authenticatedUser['id'] ?? null;

            if (empty($name)) {
                ResponseHelper::error("Document Name is required.", 400);
                return;
            }
            if (empty($type)) {
                ResponseHelper::error("Document Type is required.", 400);
                return;
            }

            if ($docId) {
                // Update existing document
                $currentStmt = $this->db->prepare("SELECT file_url FROM dormitory_documents WHERE id = ?");
                $currentStmt->execute([$docId]);
                $existing = $currentStmt->fetch(PDO::FETCH_ASSOC);
                if (!$existing) {
                    ResponseHelper::error("Document not found.", 404);
                    return;
                }

                $finalFileUrl = !empty($fileUrl) ? $fileUrl : $existing['file_url'];

                $verifiedAt = ($verStatus === 'verified') ? date('Y-m-d H:i:s') : null;
                $verifiedBy = ($verStatus === 'verified') ? $userId : null;

                $stmt = $this->db->prepare("
                    UPDATE dormitory_documents SET 
                        document_name = ?,
                        document_type = ?,
                        issuing_authority = ?,
                        permit_number = ?,
                        issue_date = ?,
                        expiration_date = ?,
                        file_url = ?,
                        file_name = COALESCE(?, file_name),
                        file_type = COALESCE(?, file_type),
                        file_size_kb = COALESCE(?, file_size_kb),
                        verification_status = ?,
                        verified_at = ?,
                        verified_by = ?,
                        verification_notes = ?,
                        is_public_for_tenants = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");

                $stmt->execute([
                    $name,
                    $type,
                    $authority,
                    $permitNumber,
                    $issueDate,
                    $expDate,
                    $finalFileUrl,
                    $fileName,
                    $fileType,
                    $fileSizeKb,
                    $verStatus,
                    $verifiedAt,
                    $verifiedBy,
                    $notes,
                    $isPublic,
                    $docId
                ]);

                ResponseHelper::success(['id' => $docId], "Document updated successfully.");
            } else {
                // New document upload
                if (empty($fileUrl)) {
                    ResponseHelper::error("Document file is required.", 400);
                    return;
                }

                $verifiedAt = ($verStatus === 'verified') ? date('Y-m-d H:i:s') : null;
                $verifiedBy = ($verStatus === 'verified') ? $userId : null;

                $stmt = $this->db->prepare("
                    INSERT INTO dormitory_documents (
                        document_name,
                        document_type,
                        issuing_authority,
                        permit_number,
                        issue_date,
                        expiration_date,
                        file_url,
                        file_name,
                        file_type,
                        file_size_kb,
                        verification_status,
                        verified_at,
                        verified_by,
                        verification_notes,
                        is_public_for_tenants,
                        created_by,
                        created_at,
                        updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");

                $stmt->execute([
                    $name,
                    $type,
                    $authority,
                    $permitNumber,
                    $issueDate,
                    $expDate,
                    $fileUrl,
                    $fileName,
                    $fileType,
                    $fileSizeKb,
                    $verStatus,
                    $verifiedAt,
                    $verifiedBy,
                    $notes,
                    $isPublic,
                    $userId
                ]);

                $newId = (int)$this->db->lastInsertId();
                ResponseHelper::success(['id' => $newId], "Document uploaded successfully.");
            }
        } catch (Throwable $e) {
            ResponseHelper::error("Failed to save document: " . $e->getMessage(), 500);
        }
    }

    /**
     * Delete document
     * Landlord only
     */
    public function deleteDocument($authenticatedUser, $data) {
        if (($authenticatedUser['role'] ?? '') !== 'landlord') {
            ResponseHelper::error("Unauthorized. Only authorized landlords can delete documents.", 403);
            return;
        }

        $id = !empty($data['id']) ? (int)$data['id'] : null;
        if (!$id) {
            ResponseHelper::error("Document ID is required.", 400);
            return;
        }

        try {
            $stmt = $this->db->prepare("DELETE FROM dormitory_documents WHERE id = ?");
            $stmt->execute([$id]);

            ResponseHelper::success(null, "Document removed successfully.");
        } catch (Throwable $e) {
            ResponseHelper::error("Failed to delete document: " . $e->getMessage(), 500);
        }
    }

    /**
     * Update verification status of a document
     * Landlord only
     */
    public function verifyDocument($authenticatedUser, $data) {
        if (($authenticatedUser['role'] ?? '') !== 'landlord') {
            ResponseHelper::error("Unauthorized. Only authorized landlords can verify documents.", 403);
            return;
        }

        $id = !empty($data['id']) ? (int)$data['id'] : null;
        $status = isset($data['verification_status']) && in_array($data['verification_status'], ['verified', 'pending_verification']) 
            ? $data['verification_status'] 
            : 'pending_verification';
        $notes = isset($data['verification_notes']) ? trim($data['verification_notes']) : null;
        $userId = $authenticatedUser['id'] ?? null;

        if (!$id) {
            ResponseHelper::error("Document ID is required.", 400);
            return;
        }

        try {
            $verifiedAt = ($status === 'verified') ? date('Y-m-d H:i:s') : null;
            $verifiedBy = ($status === 'verified') ? $userId : null;

            $stmt = $this->db->prepare("
                UPDATE dormitory_documents 
                SET verification_status = ?, verified_at = ?, verified_by = ?, verification_notes = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$status, $verifiedAt, $verifiedBy, $notes, $id]);

            ResponseHelper::success(['id' => $id, 'status' => $status], "Document verification updated.");
        } catch (Throwable $e) {
            ResponseHelper::error("Failed to update verification status: " . $e->getMessage(), 500);
        }
    }
}
