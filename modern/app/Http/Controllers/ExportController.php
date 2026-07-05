<?php

namespace App\Http\Controllers;

use App\Services\MapExporter;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    public function stcm(MapExporter $exporter, ?int $release = null): StreamedResponse
    {
        return $exporter->streamStcm($release);
    }

    public function exclusions(MapExporter $exporter): StreamedResponse
    {
        return $exporter->streamExclusions();
    }
}
