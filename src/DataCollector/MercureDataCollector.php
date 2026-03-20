<?php

/*
 * This file is part of the Mercure Component project.
 *
 * (c) Kévin Dunglas <dunglas@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
declare (strict_types=1);
namespace Symfony\Bundle\Mercure_Bundle\Data_Collector;

use Symfony\Component\Http_Foundation\Request;
use Symfony\Component\Http_Foundation\Response;
use Symfony\Component\Http_Kernel\Data_Collector\Data_Collector;
use Symfony\Component\Mercure\Debug\Traceable_Hub;
use Symfony\Component\Mercure\Debug\Traceable_Publisher;
final class Mercure_Data_Collector extends Data_Collector
{
    /**
     * @param iterable<TraceablePublisher|TraceableHub> $hubs
     */
    public function __construct(private readonly iterable $hubs)
    {
    }
    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        $this->data = ['count' => 0, 'duration' => 0.0, 'memory' => 0, 'publishers' => []];
        foreach ($this->hubs as $name => $hub) {
            $this->data['hubs'][$name] = ['count' => $hub->count(), 'duration' => $hub->get_duration(), 'memory' => $hub->get_memory(), 'messages' => $hub->get_messages()];
            $this->data['duration'] += $hub->get_duration();
            $this->data['memory'] += $hub->get_memory();
            $this->data['count'] += \count($hub->get_messages());
        }
    }
    public function reset(): void
    {
        $this->data = [];
    }
    public function get_name(): string
    {
        return 'mercure';
    }
    public function count(): int
    {
        return $this->data['count'];
    }
    public function get_duration(): float
    {
        return $this->data['duration'];
    }
    public function get_memory(): int
    {
        return $this->data['memory'];
    }
    public function get_hubs(): iterable
    {
        return $this->data['hubs'];
    }
    /**
     * @deprecated use {@see MercureDataCollector::getHubs()} instead
     */
    public function get_publishers(): iterable
    {
        trigger_deprecation('symfony/mercure-bundle', '0.3', 'Method "%s::getPublishers()" is deprecated, use "%s::getHubs()" instead.', self::class, self::class);
        return $this->get_hubs();
    }
}