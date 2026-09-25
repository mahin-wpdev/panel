<?php
/** Customer-owned, persisted server-side peaks derived from RADIUS interim counter deltas. */
declare(strict_types=1);

function jm_radius_peak_for_customer(PDO $db, int $customerId): array {
    try {
        $table = $db->prepare("SELECT 1 FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_mobile_radius_speed_peaks' LIMIT 1");
        $table->execute();
        if (!$table->fetchColumn()) {
            return ['available'=>false, 'source'=>'radius_accounting',
                'message'=>'Server collector is not installed; no phone-derived peak is shown.'];
        }
        $stmt = $db->prepare('SELECT download_bps,download_at_ms,upload_bps,upload_at_ms,
                last_recorded_ms FROM tbl_mobile_radius_speed_peaks WHERE customer_id=? LIMIT 1');
        $stmt->execute([$customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['available'=>true, 'source'=>'radius_accounting', 'has_record'=>false,
                'message'=>'Waiting for two RADIUS accounting updates from the same session.'];
        }
        return ['available'=>true, 'source'=>'radius_accounting',
            'has_record'=>true, 'download_bps'=>(int)$row['download_bps'],
            'download_at_ms'=>$row['download_at_ms'] === null ? null : (int)$row['download_at_ms'],
            'upload_bps'=>(int)$row['upload_bps'],
            'upload_at_ms'=>$row['upload_at_ms'] === null ? null : (int)$row['upload_at_ms'],
            'last_recorded_ms'=>(int)$row['last_recorded_ms'],
            'message'=>'Highest observed RADIUS accounting interval average since collection began.'];
    } catch (Throwable $error) {
        error_log('JM radius speed peak read failed ('.get_class($error).')');
        return ['available'=>false, 'source'=>'radius_accounting',
            'message'=>'Server speed history is temporarily unavailable.'];
    }
}
