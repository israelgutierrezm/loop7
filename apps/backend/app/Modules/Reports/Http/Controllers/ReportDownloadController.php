<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Brands\Models\Brand;
use App\Modules\Reports\Enums\RunStatus;
use App\Modules\Reports\Models\ClientReportRun;
use App\Modules\Reports\Services\ReportGenerator;
use App\Support\Tenancy\OrganizationScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Descarga del PDF de un informe por URL firmada (30 min). La firma la emite el
 * listado de informes sólo a quien ve la analítica de esa marca.
 */
class ReportDownloadController extends Controller
{
    public function __invoke(Request $request, string $run, ReportGenerator $generator): StreamedResponse
    {
        $model = ClientReportRun::query()->withoutGlobalScope(OrganizationScope::class)->where('public_id', $run)->first();
        abort_if($model === null || $model->status !== RunStatus::READY || $model->file_path === null, 404);

        $disk = Storage::disk(ReportGenerator::DISK);
        abort_unless($disk->exists($model->file_path), 404);

        $brand = Brand::query()->withoutGlobalScope(OrganizationScope::class)->find($model->brand_id);
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return $disk->response($model->file_path, $generator->filename($model, $brand), [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], $disposition);
    }
}
