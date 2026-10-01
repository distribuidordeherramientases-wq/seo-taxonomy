# Flujo de datos

Esta página resume el flujo de conocimiento y decisión editorial de SEO Taxonomy. Debe usarse como referencia para evitar duplicar responsabilidades entre servicios.

```mermaid
flowchart TD
    A[Catálogo / Productos / Categorías / Vocabulary] --> B[Academia]
    B --> C[Dependiente]

    D[Lingüista] --> E[Intérprete]
    E --> C

    F[Ingeniero<br/>conocimiento técnico] --> G[Clasificador]
    G --> A
    F --> H[Solucionador]

    I[Ojeador<br/>mercado / Google Shopping] --> J[Analista]
    K[Google / Bing / GA4 / Search Console / Trends] --> J

    A --> L[Auditor]
    C --> L
    G --> L

    C --> H
    E --> H
    G --> H
    I --> H
    J --> H
    L --> H

    M[Entradas / Páginas / Landings / Categorías / Imágenes] --> H

    H --> N{Decisión editorial}
    N -->|Mejorar| O[Contenido existente]
    N -->|Crear post| P[Entrada / guía]
    N -->|Crear landing| Q[Landing]
    N -->|Consolidar| R[Unificar contenido]
    N -->|No actuar| S[Sin cambio]

    O --> T[Contenido público]
    P --> T
    Q --> T
    R --> T

    T --> U[Cliente / Google / Bing / IA]
    U --> V[Consultas, visitas, clics, posicionamiento y uso]

    V --> J
    V --> L
    V --> C
    J --> H
    L --> H
    C --> H
```

## Lectura del flujo

1. **Academia** forma a Dependiente.
2. **Intérprete/Lingüista** permiten comprender cómo pregunta el cliente.
3. **Ingeniero** aporta conocimiento técnico y **Clasificador** lo estructura cuando corresponde.
4. **Auditor** analiza calidad, cobertura y coherencia interna.
5. **Analista** interpreta demanda, rendimiento y mercado.
6. **Ojeador** aporta observación externa del mercado.
7. **Solucionador** reúne estas conclusiones junto con el inventario real de contenidos.
8. Solucionador decide si hay que crear, mejorar, consolidar o no actuar.
9. Entradas, Páginas, Categorías e Imágenes ejecutan los cambios.
10. El rendimiento posterior vuelve a alimentar Analista, Auditor, Dependiente y finalmente una nueva decisión de Solucionador.

## Regla de arquitectura

**Cada servicio produce su dato; Solucionador concentra la decisión editorial.**

No se deben crear sistemas paralelos de análisis en Entradas, Páginas, Categorías o Landings si la misma información puede centralizarse en Solucionador.
