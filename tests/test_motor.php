<?php
/**
 * Cómo lee el chat la pregunta, en las partes que no tocan la base.
 *
 * Un error acá no tira un 500: contesta con toda seguridad un número de otra
 * pregunta. Así pasó con un proveedor cargado como "n", que se metía en cualquier
 * frase con una ene y se quedaba con la respuesta.
 */

require_once __DIR__ . '/../includes/motor.php';

grupo('Motor — nombres cargados por el productor');

// El caso real: el proveedor "n" aparecía dentro de "cuánto" y de "en".
es('un proveedor "n" no aparece en "cuanto gaste en la 25/26"', 'false',
   motor_nombra('cuanto gaste en la 25/26', 'n') ? 'true' : 'false');
es('ni en "margen neto de la 25/26"', 'false',
   motor_nombra('margen neto de la 25/26', 'n') ? 'true' : 'false');
// Menos de tres letras no se reconoce ni escrito solo: "costo x ha".
es('un proveedor "x" no se toma de "costo x ha"', 'false',
   motor_nombra('cuanto gaste x ha', 'x') ? 'true' : 'false');
es('el nombre escrito entero sí', 'true',
   motor_nombra('cuanto le pague a ponso', 'ponso') ? 'true' : 'false');
es('de varias palabras también', 'true',
   motor_nombra('cuanto le pague a agro norte en la 25/26', 'agro norte') ? 'true' : 'false');
es('pegado a otra palabra es otra palabra', 'false',
   motor_nombra('cuanto gaste en agroquimicos', 'agro') ? 'true' : 'false');
es('con un error de tipeo en un nombre largo', 'true',
   motor_nombra('cuanto le pague a cornaglai', 'cornaglia') ? 'true' : 'false');

grupo('Motor — qué número pide');

es('cuánto gasté, a secas, es el laboreo', 'costos_directos',
   motor_detectar_metrica(motor_normalizar('¿Cuánto gasté en la 25/26?')));
es('cuánto gasté en alquileres es el alquiler', 'costos_alquiler',
   motor_detectar_metrica(motor_normalizar('cuanto gaste en alquileres en la 26/27')));
es('sin contar el alquiler vuelve al laboreo', 'costos_directos',
   motor_detectar_metrica(motor_normalizar('cuánto gasté sin contar el alquiler')));
es('rinde de indiferencia', 'punto_equilibrio_kg_ha',
   motor_detectar_metrica(motor_normalizar('rinde de indiferencia de trigo 25/26')));
es('cuánto rindió la cosecha pide el rinde, aunque nombre la etapa', 'rendimiento_ha',
   motor_detectar_metrica(motor_normalizar('¿Cuánto rindió la cosecha de trigo?')));
