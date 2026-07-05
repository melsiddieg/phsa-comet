<style>
    :root { --comet-brown: #875503; --comet-gold: #C28119; --comet-cream: #FFFAF2; --comet-ink: #2b2b2b; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: system-ui, -apple-system, Segoe UI, Arial, sans-serif; background: var(--comet-cream); color: var(--comet-ink); }
    header { background: var(--comet-brown); color: #F8EBD5; padding: 0.6rem 1.2rem; display: flex; align-items: center; gap: 1.5rem; }
    header a { color: #F8EBD5; text-decoration: none; font-weight: 600; }
    header a:hover { color: #fff; }
    header .brand { font-size: 1.25rem; font-style: italic; letter-spacing: 0.03em; }
    header form { margin-left: auto; margin-bottom: 0; }
    header button { background: none; border: 1px solid #F8EBD5; color: #F8EBD5; padding: 0.3rem 0.8rem; border-radius: 4px; cursor: pointer; }
    main { max-width: 1280px; margin: 1.5rem auto; padding: 0 1rem; }
    .card { background: #fff; border: 1px solid #e4d9c6; border-radius: 8px; padding: 1.25rem; box-shadow: 0 1px 3px rgb(0 0 0 / 0.06); }
    .error { color: #b00020; }
    select, input { padding: 0.35rem; border: 1px solid #ccc; border-radius: 4px; }
    label { font-size: 0.8rem; color: #555; }
    .pagination { display: flex; gap: 0.25rem; list-style: none; padding: 0; flex-wrap: wrap; }
    .pagination .page-item .page-link { display:inline-block; padding: 0.3rem 0.6rem; border: 1px solid #ddd; border-radius: 4px; color: #000080; text-decoration: none; background:#fff; }
    .pagination .page-item.active .page-link { background: var(--comet-brown); color: #fff; border-color: var(--comet-brown); }
    .pagination .page-item.disabled .page-link { color: #bbb; }
</style>
