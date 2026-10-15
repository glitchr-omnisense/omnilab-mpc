<?php

namespace Omnilab\Mpc;

use Omnilab\Config;
use Omnilab\Source\SourceFactory;
use Omnilab\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * The IAU's Minor Planet Center: minor planets, comets and natural
 * satellites by number, designation or name, their orbits and their
 * observations - no key needed.
 *
 *   options:
 *     mailto: 'support@glitchr.io'     # in the User-Agent: whom the MPC writes to before it blocks a caller
 *     throttle: 0                      # seconds between two calls: the MPC publishes no rate
 *     base_uri: 'https://data.minorplanetcenter.net/api/'
 */
final class MpcSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnilab.factory_name' => 'mpc',
            'omnilab.required_options' => [],
            'throttle' => 0.0,
            'base_uri' => MpcSource::BASE_URI,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        return new MpcSource(
            $this->http ?? HttpClient::create(),
            (string) $c->get('base_uri', MpcSource::BASE_URI),
            ['User-Agent' => self::userAgent($c)],
            (float) $c->get('throttle'),
        );
    }
}
