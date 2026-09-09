# Dependiente 0.2.3 · Academia v2.1

## Objetivo

Academia v2.1 entrena al Dependiente sobre una fotografia fija del catalogo y del contenido canonico de PRO. Vocabulary es el diccionario comun que relaciona productos, categorias, posts, paginas y FAQs.

## Aula aislada

- Las busquedas normales de clientes usan solo conocimiento activo.
- L1-L7 se ejecutan en un aula aislada que puede consultar conocimiento activo + reglas `academy_stage` de la leccion actual.
- Las reglas `academy_stage` se preparan desde fuentes canonicas y nunca son visibles para clientes mientras sigan staged.
- Al finalizar una leccion, solo se promocionan a `academy`/activas si supera el quality gate y no hay errores tecnicos.
- L8 es un examen cerrado y usa solo conocimiento activo.

## Curriculo

1. Mapa del catalogo: categorias, jerarquia y Vocabulary asociado.
2. Inventario representativo: reconocimiento del catalogo con diversidad semantica.
3. TIPO y ROL: rutas canonicas del Vocabulary.
4. Caracteristicas y necesidades: progresion foundation -> combined -> deep con aplicaciones, plataformas, subtipos, atributos y etiquetas depuradas.
5. Posts y paginas: contenido editorial conectado mediante Vocabulary comun.
6. FAQs contextualizadas: cada FAQ se interpreta con su propietario (`object_type` + `object_id`) y hereda su contexto semantico.
7. Relaciones cruzadas: conceptos compartidos entre catalogo y contenido.
8. Examen integrado y regresion: muestra de L1-L7 sin ayuda de `academy_stage`.

## Criterios del generador L4

- Primero crea ejercicios simples con una relacion semantica y una caracteristica.
- Despues combina varias restricciones.
- Los ejercicios profundos pueden incorporar una etiqueta cuando aporta informacion util.
- Se descartan como etiquetas docentes los valores puramente numericos, medidas aisladas y etiquetas redundantes con atributos o Vocabulary ya presentes.
- La seleccion se distribuye de forma determinista por todo el catalogo para evitar que el temario quede concentrado en los primeros IDs.

## Diagnostico de fallos

Cada ejecucion puede etiquetar el problema como:

- `mastered`: conocimiento resuelto.
- `parser_gap`: Dependiente no interpreta bien la consulta.
- `retrieval_gap`: interpreta pero no recupera el objeto esperado.
- `ranking_gap`: recupera candidatos pero el correcto no queda en Top 8.
- `clarification_gap`: encuentra la respuesta pero pide una aclaracion innecesaria.
- `curriculum_invalid`: la pregunta preparada por Academia debe revisarse.
- `technical_error`: fallo tecnico.

## Exportacion del temario

El JSON de una leccion puede descargarse una vez preparada, incluso antes de ejecutarla. Incluye preguntas futuras (`run: null`), ground truth, dificultad, diagnosticos cuando existen y un bloque `curriculum_audit` con distribucion de tipos de pregunta, niveles de dificultad y tipos de feature.

## FAQs

`wp_seo_faq.object_type` y `object_id` identifican al propietario/contexto principal de la FAQ:

- `1`: pagina / hub.
- `2`: categoria de producto.
- `3`: producto.

Academia resuelve primero el propietario y despues usa su Vocabulary como contexto para la pregunta/respuesta.

## Seguridad

- Cargar la pantalla de Academia no inicia la formacion.
- El usuario inicia o reanuda la formacion; el Gestor de procesos solo continua un proceso ya iniciado.
- El aprendizaje de Academia no se escribe en el log de clientes ni activa aprendizaje observacional.
- Un quality gate fallido no promociona reglas staged a conocimiento operativo.
