<?php

namespace Keepsuit\LaravelOpenTelemetry\Support;

enum SamplingResult: string
{
    case Keep = 'keep';
    case Drop = 'drop';
    case Sample = 'sample';
}
