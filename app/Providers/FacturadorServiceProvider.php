<?php

namespace App\Providers;

use App\Domain\Documentos\Contracts\DocumentoRepository;
use App\Domain\Documentos\Events\DocumentoProcesado;
use App\Domain\Documentos\Events\DocumentoRecibido;
use App\Domain\Pdf\Contracts\DocumentPdfGenerator;
use App\Domain\Sunat\Contracts\SunatSender;
use App\Infrastructure\Pdf\SimpleDocumentPdfGenerator;
use App\Infrastructure\Persistence\Repositories\EloquentDocumentoRepository;
use App\Infrastructure\Sunat\GreenterSunatSender;
use App\Listeners\RegisterAuditTrail;
use App\Support\Tenants\TenantContext;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class FacturadorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
        $this->app->bind(DocumentoRepository::class, EloquentDocumentoRepository::class);
        $this->app->bind(DocumentPdfGenerator::class, SimpleDocumentPdfGenerator::class);
        $this->app->bind(SunatSender::class, GreenterSunatSender::class);
    }

    public function boot(): void
    {
        Event::listen(DocumentoRecibido::class, [RegisterAuditTrail::class, 'onDocumentoRecibido']);
        Event::listen(DocumentoProcesado::class, [RegisterAuditTrail::class, 'onDocumentoProcesado']);
    }
}
