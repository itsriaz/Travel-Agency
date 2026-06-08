<?php

declare(strict_types=1);

namespace App\Repositories;

final class BookingServiceRefundDetailRepository extends BaseRepository
{
    public function upsertForServiceEvent(int $serviceEventId, array $data): void
    {
        if ($serviceEventId <= 0 || ! $this->tableExists('booking_service_refund_details')) {
            return;
        }

        $statement = $this->db->prepare(
            'INSERT INTO booking_service_refund_details (
                service_event_id,
                treasury_account_id,
                refund_payment_method,
                customer_bank_name,
                customer_bank_account_title,
                customer_bank_account_no,
                customer_bank_iban,
                transfer_reference,
                charges,
                remarks,
                created_by_user_id,
                updated_by_user_id
             ) VALUES (
                :service_event_id,
                :treasury_account_id,
                :refund_payment_method,
                :customer_bank_name,
                :customer_bank_account_title,
                :customer_bank_account_no,
                :customer_bank_iban,
                :transfer_reference,
                :charges,
                :remarks,
                :created_by_user_id,
                :updated_by_user_id
             )
             ON DUPLICATE KEY UPDATE
                treasury_account_id = VALUES(treasury_account_id),
                refund_payment_method = VALUES(refund_payment_method),
                customer_bank_name = VALUES(customer_bank_name),
                customer_bank_account_title = VALUES(customer_bank_account_title),
                customer_bank_account_no = VALUES(customer_bank_account_no),
                customer_bank_iban = VALUES(customer_bank_iban),
                transfer_reference = VALUES(transfer_reference),
                charges = VALUES(charges),
                remarks = VALUES(remarks),
                updated_by_user_id = VALUES(updated_by_user_id)'
        );

        $statement->execute([
            'service_event_id' => $serviceEventId,
            'treasury_account_id' => $data['treasury_account_id'] ?? null,
            'refund_payment_method' => $data['refund_payment_method'] ?? 'bank_transfer',
            'customer_bank_name' => $data['customer_bank_name'] ?? null,
            'customer_bank_account_title' => $data['customer_bank_account_title'] ?? null,
            'customer_bank_account_no' => $data['customer_bank_account_no'] ?? null,
            'customer_bank_iban' => $data['customer_bank_iban'] ?? null,
            'transfer_reference' => $data['transfer_reference'] ?? null,
            'charges' => $data['charges'] ?? 0,
            'remarks' => $data['remarks'] ?? null,
            'created_by_user_id' => $data['actor_user_id'] ?? null,
            'updated_by_user_id' => $data['actor_user_id'] ?? null,
        ]);
    }

    public function findByServiceEventId(int $serviceEventId): ?array
    {
        if ($serviceEventId <= 0 || ! $this->tableExists('booking_service_refund_details')) {
            return null;
        }

        $statement = $this->db->prepare(
            'SELECT *
             FROM booking_service_refund_details
             WHERE service_event_id = :service_event_id
             LIMIT 1'
        );
        $statement->execute([
            'service_event_id' => $serviceEventId,
        ]);

        $row = $statement->fetch();

        return $row !== false ? $row : null;
    }
}
