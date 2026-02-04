<?php

namespace Keepsuit\LaravelOpenTelemetry\Instrumentation;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;
use Keepsuit\LaravelOpenTelemetry\Facades\Meter;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use Laravel\Scout\Builder;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\Engine;
use OpenTelemetry\API\Common\Time\ClockInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\Span;
use OpenTelemetry\SemConv\Attributes\DbAttributes;
use OpenTelemetry\SemConv\Metrics\DbMetrics;

use function OpenTelemetry\Instrumentation\hook;

class ScoutInstrumentation implements Instrumentation
{
    protected ?SpanInterface $activeSpan = null;

    public function register(array $options): void
    {
        if (! class_exists(EngineManager::class)) {
            return;
        }

        if (! extension_loaded('opentelemetry')) {
            return;
        }

        $this->traceSearchOperations();
    }

    protected function traceSearchOperations(): void
    {
        $searchPre = function (Engine $engine, array $params, string $className, string $methodName) {
            $this->activeSpan = null;

            if (! Tracer::traceStarted()) {
                return;
            }

            $operationName = match ($methodName) {
                'search', 'paginate' => 'search',
                'update' => 'search_update',
                'delete' => 'search_delete',
                default => sprintf('search_%s', $methodName),
            };

            $operationTarget = match (true) {
                $params[0] instanceof Builder => $this->resolveOperationNamespace($params[0]->model),
                $params[0] instanceof Collection => $this->resolveOperationNamespace($params[0]->first()),
                default => null,
            };

            $attributes = [
                DbAttributes::DB_SYSTEM_NAME => $this->resolveEngineName($engine),
                DbAttributes::DB_OPERATION_NAME => $operationName,
                DbAttributes::DB_NAMESPACE => $operationTarget,
            ];

            if ($params[0] instanceof Builder) {
                $attributes[DbAttributes::DB_QUERY_TEXT] = Str::of($params[0]->query)
                    ->limit(500)
                    ->when($methodName === 'paginate', fn (Stringable $str) => $str->append(sprintf(' (page: %d, per_page: %d)', $params[2] ?? '-', $params[1] ?? '-')))
                    ->toString();
            }

            if ($params[0] instanceof Collection) {
                $attributes[DbAttributes::DB_OPERATION_BATCH_SIZE] = $params[0]->count();
                $attributes['db.operation.batch.ids'] = $params[0]->map(fn (Model $model) => $model->getKey())->values()->join(', ');
            }

            $span = Tracer::newSpan(sprintf('%s %s', $operationName, $operationTarget))
                ->setSpanKind(SpanKind::KIND_CLIENT)
                ->setAttributes($attributes)
                ->start();

            $this->activeSpan = $span;
        };

        $searchPost = function (Engine $engine, array $params, mixed $returnValue, ?\Throwable $exception = null) {
            if ($this->activeSpan === null) {
                return;
            }

            $this->endSpan($this->activeSpan, $exception);

            if ($this->activeSpan instanceof Span) {
                $duration = $this->activeSpan->getDuration() / ClockInterface::NANOS_PER_SECOND;

                $attributes = [
                    DbAttributes::DB_SYSTEM_NAME => $this->activeSpan->getAttribute(DbAttributes::DB_SYSTEM_NAME),
                    DbAttributes::DB_NAMESPACE => $this->activeSpan->getAttribute(DbAttributes::DB_NAMESPACE),
                    DbAttributes::DB_OPERATION_NAME => $this->activeSpan->getAttribute(DbAttributes::DB_OPERATION_NAME),
                ];

                Meter::histogram(
                    name: DbMetrics::DB_CLIENT_OPERATION_DURATION,
                    unit: 's',
                    description: 'Duration of database client operations.',
                    advisory: [
                        'ExplicitBucketBoundaries' => [0.001, 0.005, 0.01, 0.05, 0.1, 0.5, 1.0, 5.0, 10.0],
                    ])
                    ->record($duration, $attributes);
            }

            $this->activeSpan = null;
        };

        hook(Engine::class, 'search', pre: $searchPre, post: $searchPost);
        hook(Engine::class, 'paginate', pre: $searchPre, post: $searchPost);
        hook(Engine::class, 'update', pre: $searchPre, post: $searchPost);
        hook(Engine::class, 'delete', pre: $searchPre, post: $searchPost);
    }

    protected function resolveEngineName(Engine $engine): string
    {
        return Str::of(class_basename($engine))
            ->replaceLast('Engine', '')
            ->snake()
            ->prepend('scout_')
            ->toString();
    }

    protected function resolveOperationNamespace(?Model $model): ?string
    {
        if ($model === null) {
            return null;
        }

        // @phpstan-ignore-next-line
        return rescue(fn () => $model->searchableAs());
    }

    protected function endSpan(SpanInterface $span, ?\Throwable $exception = null): void
    {
        if ($exception !== null) {
            $span->recordException($exception);
            $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
        }

        $span->end();
    }
}
