SOLUCIONADOR v0.2.3
===================

Objetivo
--------
Convertir preguntas reales y gaps editoriales en propuestas concretas de posts
de ayuda, evitando crear contenidos duplicados o transformar incidencias tecnicas
internas en preguntas para clientes.

Fuentes y responsabilidades
---------------------------
- Dependiente / Interprete: fuente principal. Consultas, intent, objeto,
  contexto, estado, resultados y feedback. Puede originar propuestas.
- Comentarista: solo las preguntas explicitas pueden originar propuestas.
  Problemas o limitaciones narrados en reviews actuan como refuerzo; valoraciones
  positivas, prefijos editoriales y resúmenes no se convierten en preguntas.
- Analista:
  * busquedas internas: pueden originar propuestas;
  * plan de decision: normalmente solo refuerza necesidades ya detectadas;
  * MEJORAR_PRODUCTO, IMPULSAR_CATEGORIA y decisiones estructurales no se
    convierten automaticamente en preguntas de cliente.
- Auditor:
  * probes conductuales con una consulta real: pueden originar propuestas;
  * findings: solo una allowlist de gaps editoriales/search/FAQ;
  * incidencias tecnicas de indice, schema, excerpt, servidor, BD, etc. se excluyen.
- Posts existentes: inventario editorial para saber si la solucion ya existe.

Cambios principales v0.2.3
--------------------------
1. Filtrado fuerte de Analista y Auditor para evitar propuestas falsas.
2. Separacion entre senales ORIGIN y REINFORCEMENT.
3. Reanalisis limpio: las evidencias se reconstruyen y los temas automaticos
   obsoletos se eliminan si ya no tienen evidencia. Los borradores creados se
   conservan.
4. Canonizacion mejorada: deteccion/detectar, perforacion/perforar,
   agujerear/taladrar/perforar, etc. convergen.
5. Correccion del falso positivo desbloqueada -> bloqueada/atascada mediante
   limites de palabra.
6. Cobertura editorial mejorada: el titulo manda y los H2/H3 heredan contexto
   del post en vez de interpretarse siempre de forma aislada.
7. Matching de cobertura mas estricto y preferencia por coincidencias de titulo.
8. Categorias y Vocabulary mas conservadores: umbrales relativos mas altos para
   no asociar, por ejemplo, una consulta sobre perforar una pared con soportes TV.
9. Titulos naturales para deteccion de fugas y estados como no funciona,
   no arranca, fuga, gira en vacio, etc.
10. La exportacion JSON sigue disponible e incluye las nuevas estadisticas del
    ultimo escaneo por fuente, temas limpiados y refuerzos descartados.
11. Dependiente consume directamente su log estructurado; si no existe, usa la
    busqueda interna agregada de Analista como fallback, pero la clasifica como
    Dependiente para no confundir consulta real con demanda de mercado.
12. Comentarista elimina prefijos editoriales (Positivo, Mixto, Resumen editorial,
    etc.) y no crea posts a partir de sentimiento o valoraciones genericas.
13. Comentarista por si solo nunca eleva una propuesta a create_post: requiere
    una pregunta real o evidencia independiente de Dependiente/Analista/Auditor.
14. La cobertura editorial clasifica el tipo de post y excluye noticias, piezas
    informativas, legales y comparativas como cobertura directa de soluciones.
15. Solo how-to, problem/solution y determinadas guias de compra se usan para
    decidir si una necesidad ya esta cubierta.

Regla editorial
---------------
Una propuesta NO es un post. Se guarda como registro de Solucionador con:
- pregunta representativa;
- huella canonica del problema;
- titulo propuesto;
- cobertura existente;
- categorias product_cat propuestas;
- Vocabulary canonico propuesto;
- evidencias y prioridad.

Solo al pulsar "Aprobar y crear borrador" se crea una entrada WordPress en
estado draft. El contenido y el extracto quedan vacios para que se redacten
manualmente.

Clasificacion del borrador
--------------------------
Al crear el borrador se preparan automaticamente:
- Vocabulary mediante wp_seo_object_vocabulary usando los grupos canonicos
  rol, tipo, aplicacion, plataforma y subtipo;
- categorias de producto mediante wp_seo_relations con:
    source_type   = post
    target_type   = product_cat
    relation_type = post_to_category

No se crean relaciones ordinarias post->producto. Los productos concretos se
resuelven dinamicamente por Dependiente a partir de categorias/Vocabulary.

Menu
----
SEO Taxonomy -> Herramientas -> Solucionador

Pestanas
--------
- Resumen
- Propuestas de posts
- Cobertura de posts
- Fuentes

Prueba recomendada en STAGING
-----------------------------
1. Copiar la carpeta includes/solucionador/ de esta version y, si se usa el
   paquete completo, sustituir includes/seo-includes-bootstrap.php.
2. Abrir Herramientas -> Solucionador.
3. Pulsar "Reanalizar fuentes". Es importante: v0.2.3 limpia automaticamente
   propuestas automaticas antiguas que ya no pasan los filtros nuevos.
4. Descargar el JSON y revisar:
   - propuestas;
   - titulo;
   - categorias;
   - Vocabulary;
   - coverage;
   - last_scan.accepted_by_source / discarded_by_source.
5. No aprobar un borrador hasta validar el primer JSON de v0.2.3.

Base de datos
-------------
No hay cambio de esquema respecto a v0.2.1. DB version sigue siendo 0.2.0.
