# Entrenador de Dependiente

Módulo administrativo para ejecutar bancos de preguntas contra el motor real de Dependiente sin alimentar el aprendizaje supervisado.

## Ubicación

`includes/dependiente/entrenador/`

## Uso

En WordPress abre `WooCommerce > Dependiente > Entrenador`.

El banco acepta una pregunta por línea:

- `pregunta`
- `tipo | pregunta`
- `tipo | modo | pregunta`

Tipos admitidos: `need`, `product`, `compatibility`, `symptom`, `use_case`, `comparison`, `colloquial`, `typo`, `ambiguous`, `other`. También se aceptan sus etiquetas en español, por ejemplo `síntoma`, `producto`, `comparación` o `error ortográfico`.

Modos de entrada: `need`, `product`, `tool`, `compare`.

## KPIs

- Preguntas lanzadas
- Contestadas
- Con resultados
- Sin resultados
- Resultados devueltos

Los resultados se desglosan también por tipo de pregunta.

## Exportar resultados

En `Resultados de la última ejecución`, el botón `Descargar JSON` genera un archivo con los KPI del lote, el desglose por tipo y el detalle de cada pregunta (estado, conteos, estrategia, productos principales, aclaraciones y metadatos semánticos). El archivo está pensado para adjuntarlo en ChatGPT y analizar el comportamiento del Dependiente.

## Aislamiento del aprendizaje

El Entrenador usa el mismo método `SEO_Dependiente_API::search()` que la interfaz pública, pero durante esas llamadas desactiva internamente el registro en `wp_seo_dependiente_search_log`. Las ejecuciones se guardan únicamente en las tablas propias del Entrenador.

Por tanto no dispara `SEO_Dependiente_Learning::observe_search()`, no altera los KPIs del informe de clientes y no aprueba, crea ni activa reglas semánticas.
