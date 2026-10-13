<?php
/**
 * Usage Service
 * Handles tracking, analytics, reporting, and transactions
 */

namespace PhoneServices\Services;

use PhoneServices\Core\Database;
use PhoneServices\Core\Logger;
use PhoneServices\Core\Config;

class UsageService
{
    /**
     * Record usage for any service type
     */
    public function recordUsage(int $userId, string $serviceType, int $referenceId, float $used, float $total = null, string $unit = 'unit'): int
    {
        $record = [
            'user_id' => $userId,
            'service_type' => $serviceType,
            'reference_id' => $referenceId,
            'used_value' => $used,
            'total_value' => $total,
            'unit' => $unit,
            'recorded_at' => date('Y-m-d H:i:s'),
        ];
        
        $id = Database::insert('mod_phoneservices_usage', $record);
        Logger::info('Usage recorded', ['id' => $id, 'user' => $userId, 'type' => $serviceType, 'used' => $used]);
        return $id;
    }
    
    /**
     * Get user usage summary
     */
    public function getUserUsage(int $userId, array $filters = []): array
    {
        $query = \WHMCS\Database\Capsule::table('mod_phoneservices_usage')
            ->where('user_id', $userId)
            ->select('service_type')
            ->selectRaw('COUNT(*) as total_records')
            ->selectRaw('SUM(used_value) as total_used')
            ->selectRaw('MAX(recorded_at) as last_recorded');

        if (!empty($filters['service_type'])) {
            $query->where('service_type', $filters['service_type']);
        }
        if (!empty($filters['from'])) {
            $query->where('recorded_at', '>=', $filters['from']);
        }
        if (!empty($filters['to'])) {
            $query->where('recorded_at', '<=', $filters['to']);
        }

        $usage = [];
        foreach ($query->groupBy('service_type')->get() as $row) {
            $usage[(string) $row->service_type] = (array) $row;
        }

        return $usage;
    }
    
    /**
     * Get user transactions
     */
    public function getUserTransactions(int $userId, int $limit = 50): array
    {
        return Database::select('mod_phoneservices_transactions', '*', ['user_id' => $userId], 'id', 'DESC', $limit);
    }
    
    /**
     * Get all transactions (admin)
     */
    public function getAllTransactions(array $filters = []): array
    {
        $where = [];
        if (!empty($filters['user_id'])) {
            $where['user_id'] = $filters['user_id'];
        }
        if (!empty($filters['service_type'])) {
            $where['service_type'] = $filters['service_type'];
        }
        if (!empty($filters['status'])) {
            $where['status'] = $filters['status'];
        }
        
        return Database::select('mod_phoneservices_transactions', '*', $where, 'id', 'DESC', $filters['limit'] ?? 100);
    }
    
    /**
     * Create transaction record
     */
    public function createTransaction(int $userId, string $serviceType, int $referenceId, float $amount, string $currency = 'USD', string $status = 'pending'): int
    {
        $record = [
            'user_id' => $userId,
            'service_type' => $serviceType,
            'reference_id' => $referenceId,
            'amount' => $amount,
            'currency' => $currency,
            'status' => $status,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        
        $id = Database::insert('mod_phoneservices_transactions', $record);
        Logger::info('Transaction created', ['id' => $id, 'user' => $userId, 'amount' => $amount]);
        return $id;
    }
    
    /**
     * Update transaction status
     */
    public function updateTransactionStatus(int $transactionId, string $status, array $metadata = []): bool
    {
        $update = ['status' => $status];
        if (!empty($metadata['invoice_id'])) {
            $update['invoice_id'] = $metadata['invoice_id'];
        }
        if (!empty($metadata['gateway'])) {
            $update['gateway'] = $metadata['gateway'];
        }
        if (!empty($metadata['gateway_transaction_id'])) {
            $update['gateway_transaction_id'] = $metadata['gateway_transaction_id'];
        }
        
        Database::update('mod_phoneservices_transactions', $update, ['id' => $transactionId]);
        Logger::info('Transaction status updated', ['id' => $transactionId, 'status' => $status]);
        return true;
    }
    
    /**
     * Get system-wide stats
     */
    public function getSystemStats(): array
    {
        $stats = [];
        
        $stats['total_numbers'] = Database::count('mod_phoneservices_numbers');
        $stats['active_numbers'] = Database::count('mod_phoneservices_numbers', ['status' => 'active']);
        $stats['total_calls'] = Database::count('mod_phoneservices_calls');
        $stats['total_messages'] = Database::count('mod_phoneservices_messages');
        $stats['total_esims'] = Database::count('mod_phoneservices_esims');
        $stats['active_esims'] = Database::count('mod_phoneservices_esims', ['status' => 'active']);
        $stats['total_transactions'] = Database::count('mod_phoneservices_transactions');
        $stats['total_revenue'] = $this->getTotalRevenue();
        
        // Recent activity
        $stats['recent_calls'] = Database::select('mod_phoneservices_calls', '*', [], 'id', 'DESC', 5);
        $stats['recent_messages'] = Database::select('mod_phoneservices_messages', '*', [], 'id', 'DESC', 5);
        $stats['recent_transactions'] = Database::select('mod_phoneservices_transactions', '*', [], 'id', 'DESC', 5);
        
        return $stats;
    }
    
    /**
     * Get system usage report
     */
    public function getSystemUsageReport(array $filters = []): array
    {
        $query = \WHMCS\Database\Capsule::table('mod_phoneservices_usage')
            ->selectRaw('DATE(recorded_at) as date')
            ->select('service_type')
            ->selectRaw('COUNT(*) as count')
            ->selectRaw('SUM(used_value) as total_used');

        if (!empty($filters['from'])) {
            $query->where('recorded_at', '>=', $filters['from']);
        }
        if (!empty($filters['to'])) {
            $query->where('recorded_at', '<=', $filters['to']);
        }
        if (!empty($filters['service_type'])) {
            $query->where('service_type', $filters['service_type']);
        }

        $report = [];
        foreach ($query->groupByRaw('DATE(recorded_at), service_type')->orderByRaw('date DESC')->get() as $row) {
            $report[] = (array) $row;
        }

        return $report;
    }
    
    /**
     * Get total revenue
     */
    public function getTotalRevenue(): float
    {
        $total = \WHMCS\Database\Capsule::table('mod_phoneservices_transactions')
            ->where('status', 'completed')
            ->sum('amount');
        return (float) ($total ?? 0);
    }
    
    /**
     * Get revenue by service type
     */
    public function getRevenueByService(string $from = null, string $to = null): array
    {
        $query = \WHMCS\Database\Capsule::table('mod_phoneservices_transactions')
            ->where('status', 'completed')
            ->select('service_type')
            ->selectRaw('SUM(amount) as revenue')
            ->selectRaw('COUNT(*) as count');

        if ($from) {
            $query->where('created_at', '>=', $from);
        }
        if ($to) {
            $query->where('created_at', '<=', $to);
        }

        $revenue = [];
        foreach ($query->groupBy('service_type')->get() as $row) {
            $revenue[(string) $row->service_type] = (array) $row;
        }

        return $revenue;
    }
    
    /**
     * Count user calls
     */
    public function countUserCalls(int $userId): int
    {
        return Database::count('mod_phoneservices_calls', ['user_id' => $userId]);
    }
    
    /**
     * Count user SMS
     */
    public function countUserSms(int $userId): int
    {
        return Database::count('mod_phoneservices_messages', ['user_id' => $userId]);
    }
    
    /**
     * Count user eSIMs
     */
    public function countUserEsims(int $userId): int
    {
        return Database::count('mod_phoneservices_esims', ['user_id' => $userId, 'status' => 'active']);
    }
    
    /**
     * Daily usage report (for cron)
     */
    public function generateDailyReport(): array
    {
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        $rows = \WHMCS\Database\Capsule::table('mod_phoneservices_usage')
            ->select('service_type')
            ->selectRaw('COUNT(*) as count')
            ->selectRaw('SUM(used_value) as total_used')
            ->selectRaw('AVG(used_value) as avg_used')
            ->whereRaw('DATE(recorded_at) = ?', [$yesterday])
            ->groupBy('service_type')
            ->get();

        $report = [];
        foreach ($rows as $row) {
            $report[(string) $row->service_type] = (array) $row;
        }
        
        Logger::info('Daily report generated', ['date' => $yesterday, 'services' => count($report)]);
        return $report;
    }
    
    /**
     * Cleanup old usage records
     */
    public function cleanupOldUsage(int $days = 365): int
    {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $affected = \WHMCS\Database\Capsule::table('mod_phoneservices_usage')
            ->where('recorded_at', '<', $cutoff)
            ->delete();
        Logger::info('Old usage cleaned', ['days' => $days, 'deleted' => $affected]);
        return $affected;
    }
}
