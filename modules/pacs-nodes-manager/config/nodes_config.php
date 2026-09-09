<?php
/**
 * Configuración Global del Módulo PACS NODES MANAGER
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * @package PacsNodesManager
 * @version 1.0.0
 */

return [
    // Configuración de cache
    'cache' => [
        'ttl' => 300, // Tiempo de vida del cache en segundos (5 minutos)
        'enabled' => true
    ],
    
    // Configuración de jobs
    'jobs' => [
        'polling_interval' => 5, // Intervalo de polling en segundos
        'timeout' => 300, // Timeout para C-MOVE en segundos (5 minutos)
        'max_concurrent' => 5 // Máximo de jobs concurrentes por nodo
    ],
    
    // Configuración de búsquedas
    'search' => [
        'default_limit' => 50, // Límite por defecto de resultados
        'max_limit' => 1000 // Límite máximo de resultados
    ],
    
    // Configuración de ping/test
    'ping' => [
        'timeout' => 10, // Timeout para test de conectividad en segundos
        'retry_count' => 3, // Número de reintentos
        'retry_delay' => 1 // Delay entre reintentos en segundos
    ],
    
    // Configuración de logging
    'logging' => [
        'enabled' => true,
        'file' => __DIR__ . '/../logs/pacs-nodes.log',
        'level' => 'INFO' // DEBUG, INFO, WARNING, ERROR
    ]
];
