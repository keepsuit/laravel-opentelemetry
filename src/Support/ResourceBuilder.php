<?php

namespace Keepsuit\LaravelOpenTelemetry\Support;

use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SemConv\Attributes\ServiceAttributes;
use OpenTelemetry\SemConv\ResourceAttributes;

/**
 * @internal
 */
class ResourceBuilder
{
    public static function build(): ResourceInfo
    {
        return ResourceInfoFactory::defaultResource()->merge(
            ResourceInfo::create(Attributes::create([
                ServiceAttributes::SERVICE_NAME => config('opentelemetry.service_name'),
                ResourceAttributes::SERVICE_INSTANCE_ID => config('opentelemetry.service_instance_id'),
            ]))
        );
    }
}
