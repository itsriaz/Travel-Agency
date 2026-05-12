<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Authorization;
use App\Helpers\Auth;
use App\Helpers\Flash;
use App\Services\ReportService;
use RuntimeException;

final class ReportsController extends BaseController
{
    public function index(): string
    {
        try {
            $state = (new ReportService($this->app))->reportState(
                $_GET,
                Authorization::accessibleBranchIds(),
                (int) Auth::id(),
                'screen'
            );
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
            $this->redirect('/');
        }

        return $this->view('reports/index', array_merge($state, [
            'user' => Auth::user(),
            'pageScript' => 'assets/js/reports.js',
        ]));
    }

    public function exportCsv(): never
    {
        try {
            $state = (new ReportService($this->app))->reportState(
                $_GET,
                Authorization::accessibleBranchIds(),
                (int) Auth::id(),
                'csv'
            );
        } catch (RuntimeException $exception) {
            Flash::error($exception->getMessage());
            $this->redirect('/reports');
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . rawurlencode((string) $state['csvFilename']) . '"');
        header('Cache-Control: private, no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        $stream = fopen('php://output', 'wb');
        if ($stream === false) {
            exit;
        }

        fputcsv($stream, array_map(static fn (array $column): string => (string) $column['label'], $state['columns']));
        foreach ($state['rows'] as $row) {
            fputcsv($stream, array_map(
                static fn (array $column): string => (string) ($row[$column['key']] ?? ''),
                $state['columns']
            ));
        }

        fclose($stream);
        exit;
    }
}
