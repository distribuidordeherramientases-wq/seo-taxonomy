# Ojeador

Ojeador es el servicio de **inteligencia comercial del catálogo y del mercado**. Compara cobertura de categorías, referencias de precios y productos populares, y propone oportunidades de negocio para revisión humana. **No compra, no incorpora productos y no crea categorías automáticamente.**

## Fuentes y criterio económico

**Google Merchant primero** es el modo predeterminado desde la revisión del 13/10/2026. Permite solicitar benchmarks de competitividad de precios y productos populares mediante Merchant Reports API, sin gastar créditos de SerpApi o ScraperAPI. No obstante, el acceso depende de permisos y elegibilidad de la cuenta Merchant y tiene cuotas propias.

Las **instantáneas históricas de Google Shopping** siguen conservadas, con ofertas, nombres de comercios, precios, valoraciones y fechas. Estas ofertas individuales son **diferentes** del benchmark agregado que ofrece Merchant: no deben fusionarse ni compararse como métricas idénticas.

Las conexiones de **SerpApi y ScraperAPI** continúan disponibles como **modo tradicional elegido expresamente** y para otros servicios que las necesiten. Ojeador no debe usarlas automáticamente cuando Merchant no ofrezca un precio.

## Pestañas

1. **Análisis y recomendaciones:** oportunidades comerciales, cobertura, señales de Analista y prioridades de mejora.
2. **Posición de precio:** comparación de productos propios con ofertas individuales almacenadas.
3. **Comparador de productos:** productos observados, comercios, descuentos y filtros.
4. **Operación y consultas:** historial de consultas, errores y ajustes del escaneo tradicional.
5. **Merchant · Comparación gratuita:** conexión, informes de precios competitivos y productos populares; contraste con una línea base de Ojeador PRO.

En modo Merchant-only, la sincronización Merchant es **manual y paginada**; abrir una pantalla no ejecuta búsquedas de Google Shopping de pago. El escaneo tradicional por categorías queda detenido.

## Retirada de Novedades

La antigua pestaña **Novedades** se eliminó de Ojeador en STAGING mediante el PR **#856**. Sus búsquedas de Trends/Shopping/Search se han desactivado y sus datos históricos se preservan.

El reparto actual es:

| Servicio | Función |
|---|---|
| **Ojeador** | Inteligencia comercial: precios, productos populares y huecos del catálogo. |
| **Analista** | Demanda observada: impresiones, clics y posicionamiento; las primeras señales ya son importantes. |
| **Ingeniero** | Investigación de información técnica y novedades externas verificables. |
| **Solucionador** | Preguntas y conocimiento interno; no realiza investigación web externa. |
| **Comparador** | Utiliza información de mercado ya recopilada sin repetir consultas innecesarias. |

## Qué debe decidir Dirección

- Qué **categorías** que empiezan a atraer búsquedas conviene potenciar.
- Qué **productos** tienen precios desfavorables, con comparabilidad y fuentes comprobadas.
- Qué **familias** del mercado nos faltan y merece la pena estudiar.
- Qué porcentaje de las consultas tradicionales puede sustituirse con **Merchant**, sin degradar los datos.
- Qué información debe quedar en detalle técnico en lugar de saturar la vista principal.

La futura simplificación del panel de Dirección está registrada en el **issue #857, milestone 2.3.10**. Debe agrupar recomendaciones repetidas, indicar fuente y fecha, y priorizar 3–5 ámbitos comerciales por demanda y potencial.

## Documentación de uso

Las instrucciones de cada pestaña, botón, filtro, estado, autenticación y exportación se mantienen en [**Manual operativo de Ojeador**](../manual/OJEADOR.md), sincronizado automáticamente con la GitHub Wiki.

**Cambios:** Merchant-first PR **#852** · retirada Novedades PR **#856** · mejoras UX issue **#857**.
