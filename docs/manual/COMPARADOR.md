# Comparador

## Objetivo

Comparador convierte los datos de mercado ya recopilados por **Ojeador** en un dossier interno por categoría y, cuando existe información suficiente, en una **propuesta de post de mercado** para la Editora.

La responsabilidad de cada servicio queda separada:

```text
Ojeador -> recopila productos y ofertas del mercado
Comparador -> normaliza, agrupa y analiza esos datos por categoría
Editora -> acepta la propuesta y revisa el borrador
Director -> valida el contenido antes de publicar
```

Comparador **no consulta Google Shopping ni ninguna otra fuente externa**. Consume exclusivamente snapshots persistidos por Ojeador.

## Flujo editorial v2

1. Ojeador guarda resultados de mercado para una categoría de WooCommerce.
2. El Gestor programa Comparador.
3. Comparador deduplica los resultados de Ojeador por producto.
4. Calcula el dossier de mercado de la categoría.
5. Cruza de forma interna el nivel de precios observado con el catálogo propio.
6. Si la muestra es suficiente, genera una propuesta breve de informe público.
7. La propuesta aparece en **Contenidos > Comparador**.
8. Al pulsar **Aceptar y crear borrador**, se crea un `post` de WordPress en estado `draft`.
9. El dossier interno se muestra a la Editora sobre el editor, pero no forma parte del contenido público.
10. La Editora modifica el post y decide su publicación mediante el flujo editorial normal.

No existe publicación automática.

## Qué analiza Comparador

### Mercado externo

Por cada categoría utiliza las referencias persistidas por Ojeador y conserva, cuando están disponibles:

- producto, marca y modelo;
- precio y moneda;
- tienda y URL únicamente como evidencia interna;
- fecha de observación;
- atributos explícitos extraíbles de la ficha o resultado;
- identificadores que permiten deduplicar el mismo producto visto en varios comercios.

La deduplicación prioriza:

1. Google product ID;
2. marca + modelo;
3. título normalizado.

El comercio no forma parte de la identidad cuando ya existe una identidad de producto suficiente.

### Estadística de precios

Para la moneda dominante del snapshot calcula:

- mínimo;
- primer cuartil (Q1);
- mediana;
- tercer cuartil (Q3);
- máximo;
- número de referencias con precio válido.

La mediana y el tramo Q1-Q3 sirven para describir dónde se concentra la oferta y evitan que un precio extremo domine el resumen.

### Marcas

Comparador cuenta las marcas explícitas observadas y conserva las más frecuentes para describir el panorama de la categoría. Si Ojeador no devuelve marca de forma fiable, no se inventa.

### Diferencias documentadas

Se reutiliza la normalización determinista del motor Comparador para detectar, únicamente cuando aparecen de forma explícita, atributos como:

- potencia;
- capacidad;
- presión;
- par;
- voltaje;
- frecuencia;
- carga;
- peso;
- velocidad;
- caudal;
- diámetro y dimensiones;
- temperatura.

Un atributo sólo se incorpora al dossier público si aparece en una parte suficiente de la muestra y presenta variación real. Un valor ausente permanece desconocido.

## Catálogo propio y análisis interno

Comparador obtiene también los productos publicados de la categoría y calcula el rango y mediana de sus precios.

Cuando existen precios suficientes en ambos lados, guarda una señal interna orientativa de posición frente al mercado observado:

- por debajo del mercado;
- aproximadamente alineado;
- por encima del mercado.

Esta señal es **informativa**. No modifica precios, descuentos ni márgenes y no se presenta como una recomendación automática. Una categoría puede contener gamas distintas, por lo que cualquier decisión comercial debe revisar productos realmente comparables.

## Dossier interno

El dossier interno contiene, entre otros datos:

- tamaño de la muestra externa deduplicada;
- número de precios válidos;
- rango, cuartiles y mediana;
- marcas observadas;
- número de comercios distintos;
- atributos que presentan diferencias documentadas;
- fecha del snapshot;
- referencias representativas de Ojeador;
- estadísticas del catálogo propio;
- diferencia orientativa entre mediana propia y mediana externa;
- limitaciones de la muestra.

Las tiendas, URLs y referencias de origen pueden aparecer aquí porque el dossier es exclusivamente interno.

## Informe público

La propuesta pública es deliberadamente breve. Su función es aportar **contexto de mercado** distinto del contenido de Solucionador e Ingeniero.

Puede incluir:

- amplitud de la oferta observada;
- horquilla de precios;
- mediana y zona central de precios;
- marcas presentes en la muestra;
- características que explican diferencias cuando los datos lo permiten.

No debe mostrar:

- nombres de tiendas;
- URLs de competidores;
- datos internos de margen;
- instrucciones internas;
- rankings no demostrados;
- afirmaciones de “mejor” o “peor” sin evidencia.

Con un único snapshot puede hablarse de **situación o panorama de mercado**, no de tendencia temporal. Las tendencias sólo deben generarse cuando existan snapshots históricos comparables.

## Requisito mínimo para propuesta

Una categoría necesita como mínimo:

- 5 productos externos deduplicados;
- y además al menos 3 precios válidos **o** 2 marcas identificadas.

Si Ojeador aún no tiene datos, el estado es `waiting_ojeador`.

Si existe mercado pero la muestra no alcanza el mínimo, el estado es `insufficient_market`.

## Estados

- `detected`: categoría pendiente de análisis.
- `waiting_ojeador`: no existe snapshot útil de Ojeador.
- `insufficient_market`: Ojeador tiene datos, pero la muestra todavía no permite un informe fiable.
- `proposal`: informe de mercado preparado y pendiente de decisión humana.
- `accepted`: estado transitorio de aceptación.
- `post_draft`: propuesta convertida en borrador WordPress.
- `published`: post publicado.
- `needs_update`: han cambiado Ojeador o el catálogo después de crear el post.
- `closed`: propuesta descartada por decisión humana.

## Pantalla de administración

**Contenidos > Comparador** se reduce a una sola pantalla operativa.

Muestra:

- categorías totales;
- propuestas listas;
- categorías esperando a Ojeador;
- categorías con muestra insuficiente;
- borradores;
- publicados;
- contenidos que necesitan actualización.

Para cada categoría se puede:

- ver el dossier;
- reanalizar;
- aceptar y crear borrador;
- descartar la propuesta.

También existe **Aceptar todas las propuestas listas**, procesado por lotes para evitar una petición excesivamente larga.

La edición manual de ejes, el antiguo ciclo de `CREATE_POST / IMPROVE_POST / MERGE_CONTENT / NO_ACTION`, y el Import/Export editorial dejan de formar parte del flujo principal de Comparador v2.

## Borradores WordPress

Al aceptar una propuesta se crea un `post` con:

- estado `draft`;
- etiqueta `comparativas`;
- rol editorial `comparison`;
- relación `post_to_category` con su categoría;
- vínculo persistido en `seo_comparador_post_map`;
- hash y fecha del snapshot;
- copia del dossier interno en metadatos del post.

El `post_content` contiene únicamente la propuesta pública. El dossier interno se muestra en el editor mediante un panel separado para que la Editora pueda consultar la evidencia sin riesgo de publicar nombres de tiendas o URLs externas.

## Cambios posteriores

Cuando Ojeador guarda un nuevo snapshot o cambia el catálogo propio:

- una categoría sin post vuelve a análisis;
- un post existente se marca `needs_update`;
- Comparador no sobrescribe el texto publicado.

La Editora decide qué novedades incorporar.

## Comparador de tienda

El comparador interactivo de 2-6 productos de la tienda se mantiene. Es una funcionalidad distinta del servicio editorial Comparador v2 y sigue utilizando el límite administrado por `seo_comparador_store_compare_max()`.

## Persistencia

Comparador v2 reutiliza las tablas existentes para no perder trazabilidad ni requerir una migración destructiva:

- `seo_comparador_profiles`;
- `seo_comparador_axes`;
- `seo_comparador_products`;
- `seo_comparador_values`;
- `seo_comparador_editorial`;
- `seo_comparador_post_map`;
- `seo_comparador_workflow`.

El dossier v2 se persiste dentro de la capa editorial y se copia también al metadato `_seo_comparador_market_dossier` del post cuando se crea el borrador.

