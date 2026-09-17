<?php

defined('ABSPATH') || exit;

return array(
    array(
        'key'   => 'ling_l1_catalog_language',
        'order' => 1,
        'title' => 'Lenguaje canónico del catálogo',
        'goal'  => 'Aprender cómo se nombran los conceptos reales: Vocabulary, categorías y etiquetas.',
    ),
    array(
        'key'   => 'ling_l2_actions',
        'order' => 2,
        'title' => 'Sustantivo ↔ acción',
        'goal'  => 'Descubrir verbos de uso en títulos, resúmenes y descripciones y relacionarlos con el concepto de catálogo que los respalda.',
    ),
    array(
        'key'   => 'ling_l3_synonyms',
        'order' => 3,
        'title' => 'Sinónimos y variantes explícitas',
        'goal'  => 'Importar equivalencias existentes y variantes expresadas de forma explícita, sin inventar sinónimos por simple coaparición.',
    ),
    array(
        'key'   => 'ling_l4_context',
        'order' => 4,
        'title' => 'Acción + objeto + contexto',
        'goal'  => 'Añadir contexto de catálogo a las palabras aprendidas para que una acción ambigua no lleve a la familia equivocada.',
    ),
    array(
        'key'   => 'ling_l5_ambiguity',
        'order' => 5,
        'title' => 'Ambigüedad y validación',
        'goal'  => 'Activar solo relaciones con evidencia suficiente y exigir contexto cuando una expresión puede significar varias cosas.',
    ),
    array(
        'key'   => 'ling_l6_natural_language',
        'order' => 6,
        'title' => 'Gramática, verbos y errores de escritura',
        'goal'  => 'Identificar artículos, preposiciones, determinantes, pronombres y conjunciones; lematizar formas verbales regulares/irregulares y tolerar erratas sin inventar conceptos.',
    ),
    array(
        'key'   => 'ling_l7_intent',
        'order' => 7,
        'title' => 'Intención del cliente',
        'goal'  => 'Distinguir búsqueda, problema, comparación, compatibilidad, repuesto y accesorio antes de entregar la petición al Dependiente.',
    ),
    array(
        'key'   => 'ling_l8_exam',
        'order' => 8,
        'title' => 'Examen conversacional y regresión',
        'goal'  => 'Probar frases nuevas generadas desde lo aprendido y comprobar que el Intérprete entrega una búsqueda coherente al Dependiente.',
    ),
);
