# Solucionador

Ruta: **SEO Taxonomy → Contenidos → Solucionador**

- **Analizar ahora** [Proceso].
- selector de ventana 90 / 180 / 365 días.
- **Reanalizar fuentes** [Proceso].

## Propuestas editoriales
Tabla Prioridad, Pregunta/título, Clasificación propuesta, Evidencias, Cobertura, Acción.

- **Aprobar y crear borrador** [Guarda]: crea borrador, no publica.
- selector **Observar / Mantener candidato / Descartar**.
- **Guardar** registra la decisión.

## Cobertura editorial existente
Tabla Post, Ámbito, Texto detectado, Huella canónica.

## Fuentes y evidencias
Bloques Fuentes del Solucionador y evidencias acumuladas.

## Datos internos

Pestaña de solo lectura para inspeccionar directamente las tablas que sostienen las decisiones del servicio:

- `seo_solucionador_topics`: temas, títulos sugeridos, cobertura, acción recomendada, prioridad y posts/borradores relacionados.
- `seo_solucionador_evidence`: evidencias por tema, fuente, texto, ocurrencias y peso.
- `seo_solucionador_post_topics`: huellas de títulos y H2/H3 de posts que forman la cobertura editorial existente.

La tabla de temas permite comprobar de forma directa qué filas tienen `create_post`, cuáles recomiendan ampliar contenido existente y cuáles ya están cubiertas.

**Descargar resultados JSON** [Exporta].

## Compatibilidad interna

El nombre visible del servicio es **Solucionador**. Se conservan el slug administrativo `seo-solucionador`, las clases `SEO_Solucionador_*`, las tablas, metadatos, acciones y datos existentes para no romper compatibilidad.
