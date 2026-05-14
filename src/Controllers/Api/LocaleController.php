<?php

declare(strict_types=1);

namespace EduQR\Controllers\Api;

use EduQR\Support\Database;

final class LocaleController
{
    public function index(): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->query(
            'SELECT code, label_native, label_english, is_rtl, is_active, sort_order
               FROM locales
              WHERE is_active = 1
              ORDER BY sort_order, code'
        );

        $locales = $stmt->fetchAll();

        foreach ($locales as &$row) {
            $row['is_rtl']    = (bool) $row['is_rtl'];
            $row['is_active'] = (bool) $row['is_active'];
            $row['sort_order'] = (int) $row['sort_order'];
        }
        unset($row);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['data' => $locales], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
