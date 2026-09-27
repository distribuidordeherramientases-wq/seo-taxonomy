# Contexto común del proyecto SEO Taxonomy

Este documento define la base común de información para cualquier conversación, especialista o agente que trabaje sobre **SEO Taxonomy** y **distribuidordeherramientas.es**.

Su función no es sustituir al código ni a la Wiki, sino indicar qué fuentes deben consultarse y qué reglas deben respetarse para evitar respuestas basadas en información antigua o supuestos.

## 1. Fuente de verdad

Para cualquier cuestión sobre el estado real del plugin, utilizar este orden de prioridad:

1. **Código actual del repositorio GitHub**  
   Repositorio: `distribuidordeherramientases-wq/seo-taxonomy`

2. **Wiki mantenida desde `docs/wiki/`**  
   Contiene la documentación funcional y comercial del producto.

3. **README, CHANGELOG y documentación técnica del repositorio**

4. **Conversaciones anteriores**  
   Sirven como contexto histórico, pero nunca deben prevalecer sobre el código o la documentación actual.

Si existe contradicción entre una conversación antigua y el código actual, prevalece el código.

## 2. Estado base actual

- Producto: **SEO Taxonomy**
- Tipo: plugin para **WordPress + WooCommerce**
- Versión actual documentada: **2.3.7**
- Objetivo: plataforma de gestión SEO, semántica y comercial para catálogos WooCommerce.
- Arquitectura SEO principal: **Cluster → Hub primario → Hub secundario → Categoría → Producto**.
- Vocabularios semánticos principales: **ROL, TIPO, APLICACIÓN, PLATAFORMA, SUBTIPO**.

El plugin incluye, entre otros ámbitos:

- productos;
- categorías;
- páginas y entradas;
- imágenes;
- taxonomía y relaciones;
- vocabulario semántico y atributos;
- importación/exportación;
- proveedores;
- Google Shopping;
- informes;
- FAQs y redirecciones;
- procesos y workers;
- automatización;
- aprendizaje y asistencia inteligente.

## 3. Servicios principales

Los servicios deben entenderse según su implementación real en el código y su documentación en la Wiki.

- **Clonador**: reconstrucción/sincronización controlada PRO → STAGING.
- **Ojeador**: observación de mercado y Google Shopping.
- **Dependiente**: búsqueda guiada, atención y aprendizaje.
- **Intérprete**: traducción de lenguaje natural a semántica utilizable por el sistema.
- **Academia / Lingüista**: formación y evolución del conocimiento semántico.
- **Analista**: interpretación de señales y análisis estratégico.
- **Comentarista**: gestión de evidencias, comentarios, vídeos, publicaciones sociales y enlaces externos.
- **Auditor**: detección y priorización de incoherencias o problemas.
- **Solucionador**: consolidación de señales y detección de necesidades editoriales.
- **Clasificador**: clasificación y organización semántica cuando corresponda.

No asumir funciones que no estén implementadas o documentadas.

## 4. Regla de trabajo técnico

Antes de proponer cambios técnicos:

1. inspeccionar la implementación existente;
2. localizar funciones, clases, tablas, hooks, workers y procesos relacionados;
3. reutilizar la arquitectura actual siempre que sea posible;
4. evitar crear sistemas paralelos que dupliquen funcionalidades existentes;
5. distinguir claramente entre código existente, propuesta y trabajo pendiente.

Principio de trabajo:

> Hay que acoplarse a los procesos, funciones y programas existentes; no inventar una arquitectura paralela sin haber comprobado antes lo que ya existe.

## 5. STAGING y PRO

Como criterio general de desarrollo:

- los cambios se prueban primero en **STAGING**;
- PRO se utiliza después de validar;
- STAGING puede tener configuración, imágenes, índices o aprendizaje distintos de PRO;
- no asumir que una diferencia entre ambos entornos implica un error.

Las operaciones destructivas o de clonación deben respetar las advertencias y perímetros definidos por el propio plugin.

## 6. Uso por especialistas

Todos los especialistas deben partir de esta misma base, aunque tengan funciones diferentes.

### Desarrollo / Arquitectura
Prioriza código, integración, compatibilidad WordPress/WooCommerce, base de datos, rendimiento, workers, procesos y mantenimiento.

### SEO / Catálogo
Prioriza arquitectura SEO, categorías, taxonomía, semántica, contenido, Search Console, Google Shopping y calidad del catálogo.

### Comercial
Analiza únicamente capacidades reales o claramente identificadas como roadmap. Puede utilizar la Wiki como base de producto, pero debe contrastar afirmaciones funcionales importantes con el código cuando sea necesario.

### Legal
Debe distinguir entre funcionamiento técnico real, condiciones comerciales previstas y requisitos legales externos. No debe asumir que una función técnica implica cumplimiento legal.

### Financiero
Debe trabajar con datos económicos aportados expresamente para ese análisis. No inferir costes, márgenes o ingresos desde el código.

### Documentación
Debe utilizar la interfaz y el código real para mantener la Wiki. Las capturas de pantalla complementan la documentación, pero no sustituyen a la implementación como fuente técnica.

## 7. Política de actualización

Este proyecto es dinámico.

Cuando cambie el plugin:

- actualizar el código;
- actualizar la documentación afectada en `docs/wiki/`;
- actualizar este documento solo si cambia una decisión estructural o una regla común;
- evitar copiar grandes cantidades de estado temporal aquí.

Los detalles muy cambiantes deben permanecer en el código, informes o páginas específicas de la Wiki, no duplicados en este contexto común.

## 8. Información pública y privada

El repositorio es una fuente compartida del proyecto.

No guardar aquí:

- tokens;
- contraseñas;
- claves API;
- credenciales;
- datos personales sensibles;
- información financiera privada no destinada a publicación;
- borradores legales confidenciales.

La información interna o confidencial debe mantenerse fuera del repositorio público.

## 9. Regla para nuevas conversaciones

Cuando una conversación trate sobre SEO Taxonomy y el dato pueda haber cambiado:

**consultar GitHub antes de responder**.

No pedir al usuario que vuelva a explicar una parte del plugin que pueda verificarse directamente en el repositorio o en la Wiki.
