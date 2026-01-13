<?php

namespace Keepsuit\LaravelOpenTelemetry\Support;

enum SamplingResult
{
    case Keep;
    case Drop;
    case Forward;
}
