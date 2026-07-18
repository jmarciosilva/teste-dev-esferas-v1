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

    /**
     * Versão de connection() que nunca lança exceção — devolve null se o
     * Redis estiver indisponível. Usada em todo lugar onde o cache é
     * opcional (cache-aside com fallback, página de métricas) e não deve
     * derrubar a funcionalidade principal.
     */
    public static function tryConnection(): ?Redis
    {
        try {
            return self::connection();
        } catch (Throwable $e) {
            return null;
        }
    }
}
