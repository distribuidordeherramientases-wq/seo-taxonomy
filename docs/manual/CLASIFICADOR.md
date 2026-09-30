# Clasificador

Ruta: **SEO Taxonomy → Semántica → Clasificador**

El **Clasificador** es el servicio que analiza productos del catálogo y propone cómo completar o corregir su clasificación semántica y, cuando procede, sus atributos técnicos. No es el maestro de etiquetas: los términos canónicos se gestionan en **Etiquetas** y **Atributos**; el Clasificador utiliza esos maestros para decidir qué valores encajan con cada producto.

## Qué problema resuelve

Su objetivo es localizar productos con huecos o anomalías y proponer valores para:

- **TIPO**: qué es el producto.
- **ROL / Ámbito**: área funcional asociada al TIPO.
- **APLICACIÓN**: para qué se usa.
- **PLATAFORMA**: sistema, familia o entorno compatible.
- **SUBTIPO**: variante más específica dentro del TIPO.
- **Atributos técnicos**: valores canónicos como potencia, tensión, presión, capacidad, dimensiones u otros atributos definidos en el maestro.

El Clasificador trabaja como proceso de análisis y propuesta. La pantalla administrativa consume resultados persistidos de los jobs; no recorre todo el catálogo de forma síncrona al abrir la página.

## Qué información analiza

Para cada producto construye un contexto con varias fuentes.

### 1. Información local del producto

Puede utilizar:

- título;
- slug;
- extracto;
- descripción;
- categorías WooCommerce;
- etiquetas actuales;
- marca/fabricante cuando están disponibles;
- SKU y otros datos internos como evidencia;
- clasificación semántica ya asignada;
- atributos canónicos ya existentes.

Los datos internos pueden participar en la clasificación aunque no tengan por qué mostrarse al cliente.

### 2. Información del proveedor

Cuando existe, utiliza información conocida del proveedor, como:

- nombre y descripción del proveedor;
- categoría del proveedor;
- fabricante;
- MPN;
- SKU del proveedor;
- texto bruto o estructurado importado.

Esta información se utiliza como evidencia; no se publica automáticamente ni crea términos por sí sola.

### 3. Contexto externo ya conocido

El Clasificador puede aprovechar contexto externo cacheado asociado al producto:

- datos estructurados;
- metadatos;
- cuerpo de páginas conocidas.

En modo profundo puede forzar la actualización del contexto externo configurado antes de clasificar. La evidencia externa se pondera según su relevancia.

### 4. Patrones aprendidos del catálogo

El Clasificador aprende estadísticamente de las asignaciones canónicas ya revisadas.

Utiliza principalmente dos tipos de perfil:

- **perfil por TIPO**: observa qué APLICACIÓN, SUBTIPO o PLATAFORMA aparecen de forma repetida en productos que comparten un TIPO;
- **perfil por categoría**: observa cómo se clasifican los productos ya revisados dentro de las categorías reales del producto.

Una categoría, por tanto, **no impone automáticamente sus etiquetas al producto**. Actúa como una señal estadística adicional. El sistema considera dominancia, cobertura, número de ejemplos y margen respecto a otras alternativas. Un patrón basado en pocos ejemplos no se trata como seguro aunque represente el 100 % de esos ejemplos.

## Cómo decide una propuesta

El flujo conceptual es:

1. Lee la clasificación y atributos actuales.
2. Detecta qué grupos faltan.
3. Construye el contexto del producto.
4. Compara el contenido con el vocabulario canónico.
5. Añade señales de proveedor y contexto externo cuando existen.
6. Consulta patrones aprendidos por TIPO y por categoría.
7. Puntúa candidatos.
8. Clasifica el resultado según su seguridad.
9. Guarda la propuesta para revisión en la pantalla del Clasificador.

Para la identidad semántica, **TIPO se resuelve antes** porque puede servir de señal para otros grupos.

**ROL** tiene una regla especial: cuando falta, se deriva del TIPO canónico actual o de un TIPO propuesto como seguro mediante la relación TIPO → ROL.

## Estados de las propuestas

En la matriz pueden aparecer varios estados:

- **Actual**: el producto ya tiene ese valor canónico.
- **Segura**: la evidencia supera los umbrales necesarios para que pueda incluirse en la aplicación de propuestas seguras.
- **Revisar**: existe una propuesta razonable, pero requiere decisión humana.
- **Nueva etiqueta / vocabulario nuevo**: hay evidencia de un concepto que no está disponible como término canónico; debe revisarse antes de darlo de alta.
- **Sin resolver / Sin analizar**: no hay evidencia suficiente o todavía no existe un resultado persistido para ese grupo.
- **Derivada**: caso especial de ROL obtenido a partir de la relación canónica del TIPO.

## Clasificación de atributos

Además de etiquetas semánticas, el motor puede analizar atributos canónicos.

Para atributos controlados busca coincidencias con:

- términos activos;
- aliases;
- título;
- identidad;
- proveedor;
- datos estructurados externos;
- etiquetas y marca;
- contenido local;
- categorías.

Para atributos numéricos exige evidencia explícita y reconoce formatos técnicos y unidades, por ejemplo:

- V;
- W / kW;
- A / Ah / mAh;
- Hz;
- rpm;
- kg;
- mm / cm / m;
- bar / psi;
- N·m;
- L/min.

No inventa un valor numérico si no existe una señal suficientemente explícita.

También puede detectar que falta una **definición de atributo** en el maestro. Esa detección es una propuesta de modelado, no una creación automática.

## Pantalla y flujo de trabajo

Dentro de **Semántica → Clasificador** aparecen las subsecciones:

- **Etiquetas de productos**.
- **Ingeniero → vocabulario**.
- **Atributos de productos**.
- **Etiquetas de categorías**.
- **Formato / ejemplos**.

La entrada principal para el Clasificador de productos es **Etiquetas de productos**.

### Filtros

La pantalla es correctiva: no precarga todo el catálogo.

Se puede filtrar por:

- búsqueda de producto / SKU / ID;
- categoría;
- cobertura;
- prioridad;
- número de filas.

Las prioridades permiten trabajar por hueco:

- **P1**: falta TIPO.
- **P2**: falta ROL.
- **P3**: falta APLICACIÓN.
- **P4**: falta SUBTIPO.
- **P5**: falta PLATAFORMA.

## Analizar filtro · rápido

Crea un job para los productos que coinciden con el filtro y presentan los huecos seleccionados.

El modo rápido:

- puede reutilizar propuestas/caché válida si el contexto no ha cambiado;
- procesa en segundo plano;
- persiste resultados para que la pantalla solo tenga que leerlos.

Es la opción normal para revisar cobertura y continuar trabajos ya conocidos.

## Analizar filtro · profundo

También crea un job en segundo plano, pero fuerza una revisión más intensa.

El modo profundo:

- recalcula las propuestas;
- fuerza refresco del contexto externo conocido cuando procede;
- evita apoyarse únicamente en una caché anterior.

Úsalo cuando quieras revisar un conjunto dudoso, cuando hayan cambiado fuentes o cuando necesites regenerar evidencia.

## Ingeniero → vocabulario

Esta subpestaña conecta el conocimiento técnico aprobado de **Ingeniero** con los maestros canónicos.

Objetivo:

> detectar conceptos que Ingeniero considera relevantes para una categoría pero que todavía pueden no estar representados correctamente en Etiquetas o Atributos.

Flujo:

1. seleccionar una categoría con conocimiento activo de Ingeniero;
2. pulsar **Analizar conocimiento de Ingeniero**;
3. el Clasificador compara esa evidencia con los maestros existentes;
4. muestra atributos/dimensiones y conceptos semánticos detectados;
5. marca cada propuesta como **Candidato nuevo**, **Posible equivalente** o **Ya cubierto**;
6. muestra evidencia y confianza;
7. la revisión humana decide si hay que ampliar el maestro.

La primera versión es deliberadamente de solo lectura. **No crea etiquetas, términos ni atributos y no modifica productos.**

El informe puede proponer, por ejemplo:

- atributos numéricos como presión máxima, caudal, potencia, tensión, nivel sonoro o temperatura de trabajo;
- atributos controlados como fuente de alimentación y sus posibles términos;
- conceptos semánticos de aplicación, plataforma o subtipo cuando aparecen de forma clara en la evidencia.

La comparación intenta detectar equivalentes existentes antes de declarar un hueco, para reducir duplicados del tipo `presión` / `presión máxima` / `presión de trabajo`.

La arquitectura prevista es:

**Ingeniero descubre → Clasificador estructura → revisión humana amplía maestros → Clasificador completa productos → Auditor mide cobertura.**

## Jobs y procesamiento adaptativo

El Clasificador utiliza una cola persistente. No intenta procesar miles de productos dentro de la petición del navegador.

Los jobs:

- dividen el trabajo en lotes;
- adaptan tamaño de lote y pausa según tiempo, memoria y presión del servidor;
- guardan progreso, errores, caché y propuestas;
- pueden ser atendidos por Action Scheduler y WP-Cron;
- permiten **Pausar**, **Reanudar** y **Cancelar**.

El estado global puede revisarse también en **SEO Taxonomy → Procesos**, donde Clasificador aparece como proceso manual gestionado después de arrancarlo.

## Aplicar propuestas seguras

El botón **Aplicar propuestas seguras** crea un trabajo de aplicación sobre el filtro actual.

Solo intenta aplicar propuestas persistidas con estado seguro. Las propuestas de revisión, vocabulario nuevo o sin resolver no se aceptan por esta acción.

Aunque una propuesta sea segura, conviene utilizar filtros acotados al validar cambios importantes del catálogo.

## Confirmación manual

La matriz permite revisar cada producto y cada grupo antes de guardar.

Acciones habituales:

- **Confirmar fila**: confirma los valores revisados para ese producto.
- **Confirmar datos**: guarda la propuesta editada cuando corresponde.
- alta manual de un término nuevo: se realiza desde los maestros correspondientes, no como creación silenciosa del Clasificador.

La pantalla distingue entre lo que **ya está guardado** y lo que sigue siendo una **propuesta**.

## Qué modifica y qué no

### Puede modificar, cuando se ejecuta una acción de aceptación

- asignaciones canónicas del producto;
- atributos aprobados por los flujos de confirmación;
- propuestas seguras mediante el job de aplicación.

### No hace automáticamente por el mero análisis

- no cambia la categoría WooCommerce del producto;
- no mueve productos entre categorías;
- no crea vocabulario canónico sin aceptación explícita;
- no sustituye los maestros de Etiquetas o Atributos;
- no publica al cliente los datos del proveedor;
- no aplica propuestas de estado **Revisar**;
- no considera que pertenecer a una categoría sea suficiente para copiar su clasificación.

## Relación entre Etiquetas, Clasificador y Atributos

Una forma sencilla de recordarlo:

- **Etiquetas** = define el vocabulario semántico permitido.
- **Atributos** = define el vocabulario y modelo técnico permitido.
- **Clasificador** = analiza los productos y propone qué etiquetas y atributos canónicos les corresponden.
- **Procesos** = muestra el estado operativo de los jobs que ya se han lanzado.

En resumen:

> El Clasificador mira lo que sabemos de un producto, compara esa información con el vocabulario canónico y con patrones fiables de productos semejantes, y propone cómo completar o corregir su clasificación sin convertir esas propuestas en cambios silenciosos.
