## [0.1.2] — 2026-11-27 — Carga por lotes visible y menú de Core (#3019)

- Identifica la pestaña Importar por lotes y el botón de carga; cada uno de los siete bloques permite seleccionar varios CSV y muestra sus nombres y cantidad. Mantiene el motor, límites, prechequeo y confirmación existentes.
- Registra ficha y módulo propios mediante hooks opcionales de Core. Con Core activo, Pro aparece como submenú de TaxoCommerce Core; sin Core, mantiene su menú autónomo. No cambia el slug de pantalla ni los trabajos guardados.
- Lite y el importador original permanecen intactos.

## [0.1.1] — 2026-11-27 — Flujo limpio por contenido y recepción privada (#2989)

- Cada bloque exporta automáticamente su esquema completo de campos compatibles, sin selección de columnas. El tipo de importación depende únicamente del bloque elegido; solo se reconocen las columnas del CSV dentro de ese esquema.
- Recepción de lotes con la protección de Lite 0.1.6: origen HTTP verificado, copia privada sin Media ni filtros MIME, integridad y permisos, error identificado por archivo, alternativa de almacenamiento privado. Se conserva el bloqueo único del sitio.
- Mantiene los siete bloques, prechequeo, confirmación, colas, cinco perfiles de carga, exportación reanudable, histórico, manual técnico y chequeo de código. Lite y el original quedan intactos.

## [0.1.0] — 2026-11-26 — Primera edición independiente Pro (#2989)

- Base: TaxoCommerce Import-Export Lite 0.1.5, copiada con namespace, dominio, rutas, acciones, opciones y archivos propios.
- Siete bloques independientes por pestaña: páginas, productos, categorías WordPress, entradas, categorías de producto y etiquetas de ambos tipos.
- Cola de hasta 20 CSV por bloque, orden natural por nombre, mapeo automático por archivo, prechequeo de solo lectura y confirmación explícita de escritura.
- Exportaciones CSV por lotes con descarga autorizada, punto de control de bytes, pausa y reanudación.
- Cinco niveles adaptativos de carga, telemetría local CPU/memoria/tiempo, bloqueo compartido, recuperación explícita de interrupciones, historial de cinco trabajos por bloque.
- Cabecera, versión, fecha, histórico, chequeo funcional, chequeo de código autónomo y manuales propios.
- No sustituye ni modifica Lite ni el Import-Export original. La primera edición no incluye Media ZIP ni formatos legacy. No se instala en producción.
