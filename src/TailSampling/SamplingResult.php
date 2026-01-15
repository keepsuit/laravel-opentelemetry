<?php

namespace Keepsuit\LaravelOpenTelemetry\TailSampling;

enum SamplingResult
{
    case Keep;
    case Drop;
    case Forward;
}
