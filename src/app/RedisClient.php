<?php

class RedisClient
{
    private static ?Redis $instance = null;

    public static function connection(): Redis
    {
        if (self::$instance === null) {
            $host = getenv('REDIS_HOST') ?: 'redis';
            $port = (int) (getenv('REDIS_PORT') ?: 6379);

            $redis = new Redis();
            // Timeout curto de conexão + falha convertida numa exceção única e
            // previsível: sem isso, um host de Redis fora do ar dispara um
            // warning nativo do driver (php_network_getaddresses) em vez de
            // algo que o chamador consiga capturar de forma confiável.
            $connected = @$redis->connect($host, $port, 1.0);
            if (!$connected) {
                throw new RuntimeException("Não foi possível conectar ao Redis em {$host}:{$port}.");
            }

            self::$instance = $redis;
        }

        return self::$instance;
    }
}
