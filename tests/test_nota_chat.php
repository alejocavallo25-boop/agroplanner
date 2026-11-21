<?php
/**
 * Notas del lote desde el chat: qué frases arrancan una nota y cuáles no.
 *
 * El riesgo es doble: que "quiero anotar un gasto" pase a ser una nota (y se
 * pierda la carga del gasto), o que una pregunta sobre las notas arranque una.
 */

require_once __DIR__ . '/../includes/motor.php';

grupo('Chat — arrancar una nota del lote');

$que = fn(string $f) => motor_pide_alta_guiada(motor_normalizar($f), $f);

es('"quiero guardar una nota"', 'nota', $que('quiero guardar una nota'));
es('"guardá una observación en La Loma"', 'nota', $que('guardá una observación en La Loma'));
es('"anotá una nota: hay malezas"', 'nota', $que('anotá una nota: hay malezas'));
es('"nueva nota"', 'nota', $que('nueva nota'));
es('"quiero registrar observaciones del lote"', 'nota', $que('quiero registrar observaciones del lote'));

es('"quiero anotar un gasto" sigue siendo gasto', 'gasto', $que('quiero anotar un gasto'));
es('"quiero anotar" a secas sigue siendo gasto', 'gasto', $que('quiero anotar'));
es('"cargar un alquiler" sigue siendo alquiler', 'alquiler', $que('cargar un alquiler'));
es('"qué notas tengo" no carga', null, $que('qué notas tengo del lote'));
es('"mostrame las observaciones" no carga', null, $que('mostrame las observaciones'));

grupo('Chat — los pasos de una nota');
$pasos = motor_alta_pasos('nota');
es('pregunta el lote y el texto, en ese orden', 'lote_id,texto', implode(',', array_keys($pasos)));
es('falta el texto si ya está el lote', 'texto', motor_alta_paso_pendiente(['que' => 'nota', 'lote_id' => 3]));
es('se puede corregir el lote', 'lote_id', motor_alta_pide_correccion('corregir el lote', 'nota', ['que' => 'nota']));
