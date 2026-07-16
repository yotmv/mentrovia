<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Services\BusinessProfileCsvImporter;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OnboardingTemplateController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $stream = fopen('php://output', 'w');

            if ($stream === false) {
                return;
            }

            fputcsv($stream, BusinessProfileCsvImporter::headers(), ',', '"', '');
            fputcsv($stream, array_fill(0, count(BusinessProfileCsvImporter::headers()), ''), ',', '"', '');
            fclose($stream);
        }, 'mentrovia-company-profile.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
