<?php
class DashboardRepository {
    private $conn;

    public function __construct($dbConnection) {
        $this->conn = $dbConnection;
    }

    public function getTotalConsumption($identifier, $val, $start, $end) {
        $stmt = $this->conn->prepare("SELECT COALESCE(SUM(energy), 0) as totalEnergy, COALESCE(SUM(cost), 0) as totalCost, COUNT(*) as entryCount FROM consumption_logs WHERE $identifier = ? AND timestamp >= ? AND timestamp < ?");
        $stmt->execute([$val, $start, $end]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getDailyBreakdown($identifier, $val, $start, $end) {
        // Safe to use DATE() in GROUP BY, but WHERE clause uses SARGable boundaries
        $stmt = $this->conn->prepare("SELECT DATE(timestamp) as day, AVG(power) as avgPower, MAX(power) as peakPower, SUM(energy) as totalEnergy, SUM(cost) as totalCost, COUNT(*) as entries FROM consumption_logs WHERE $identifier = ? AND timestamp >= ? AND timestamp < ? GROUP BY DATE(timestamp) ORDER BY day DESC");
        $stmt->execute([$val, $start, $end]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getHourlyBreakdown($identifier, $val, $start, $end) {
        $stmt = $this->conn->prepare("SELECT HOUR(timestamp) as hour, SUM(energy) as totalEnergy, AVG(power) as avgPower, MAX(power) as peakPower, SUM(cost) as totalCost, COUNT(*) as entries FROM consumption_logs WHERE $identifier = ? AND timestamp >= ? AND timestamp < ? GROUP BY HOUR(timestamp) ORDER BY hour ASC");
        $stmt->execute([$val, $start, $end]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTransactionHistory($identifier, $val, $limit, $offset, $startDate = null, $endDate = null) {
        if ($startDate && $endDate) {
            $stmt = $this->conn->prepare("SELECT * FROM consumption_logs WHERE $identifier = ? AND timestamp >= ? AND timestamp <= ? ORDER BY timestamp DESC LIMIT ? OFFSET ?");
            $stmt->bindValue(1, $val);
            $stmt->bindValue(2, $startDate);
            $stmt->bindValue(3, $endDate);
            $stmt->bindValue(4, (int)$limit, PDO::PARAM_INT);
            $stmt->bindValue(5, (int)$offset, PDO::PARAM_INT);
        } else {
            $stmt = $this->conn->prepare("SELECT * FROM consumption_logs WHERE $identifier = ? ORDER BY timestamp DESC LIMIT ? OFFSET ?");
            $stmt->bindValue(1, $val);
            $stmt->bindValue(2, (int)$limit, PDO::PARAM_INT);
            $stmt->bindValue(3, (int)$offset, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getGroupedHistory($identifier, $val, $filter, $limit, $offset, $startDate = null, $endDate = null) {
        $groupBy = "";
        $select = "SUM(energy) as totalEnergy, SUM(cost) as totalCost, AVG(power) as avgPower, MAX(power) as peakPower, COUNT(*) as entries";
        
        if ($filter === 'daily') {
            $groupBy = "GROUP BY DATE(timestamp)";
            $select .= ", DATE(timestamp) as group_date";
        } else if ($filter === 'weekly') {
            $groupBy = "GROUP BY YEAR(timestamp), WEEK(timestamp)";
            $select .= ", CONCAT(YEAR(timestamp), '-W', LPAD(WEEK(timestamp), 2, '0')) as group_date";
        } else if ($filter === 'monthly') {
            $groupBy = "GROUP BY YEAR(timestamp), MONTH(timestamp)";
            $select .= ", CONCAT(YEAR(timestamp), '-', LPAD(MONTH(timestamp), 2, '0')) as group_date";
        }

        if ($startDate && $endDate) {
            $query = "SELECT $select FROM consumption_logs WHERE $identifier = ? AND timestamp >= ? AND timestamp <= ? $groupBy ORDER BY timestamp DESC LIMIT ? OFFSET ?";
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(1, $val);
            $stmt->bindValue(2, $startDate);
            $stmt->bindValue(3, $endDate);
            $stmt->bindValue(4, (int)$limit, PDO::PARAM_INT);
            $stmt->bindValue(5, (int)$offset, PDO::PARAM_INT);
        } else {
            $query = "SELECT $select FROM consumption_logs WHERE $identifier = ? $groupBy ORDER BY timestamp DESC LIMIT ? OFFSET ?";
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(1, $val);
            $stmt->bindValue(2, (int)$limit, PDO::PARAM_INT);
            $stmt->bindValue(3, (int)$offset, PDO::PARAM_INT);
        }
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
