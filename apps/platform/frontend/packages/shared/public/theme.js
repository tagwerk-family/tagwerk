// Renamed from Tagwerk: carry what this browser stored under the old prefix
// over once, before anything reads it — otherwise a wall would forget its
// entry, language and theme on the first start after the update.
try {
  [localStorage, sessionStorage].forEach(function (store) {
    Object.keys(store).forEach(function (key) {
      if (key.indexOf('tagwerk-') === 0) {
        var renamed = 'fahoba-' + key.slice(8)
        if (store.getItem(renamed) === null) {
          store.setItem(renamed, store.getItem(key))
        }
        store.removeItem(key)
      }
    })
  })
}
catch (e) {
  // Storage blocked: there is nothing to carry over.
}

// Before the first paint: without this line the wall flashed light at night
// on every reload until the response from /api/theme arrived. Without a
// stored value the system setting applies. A file, not inline, because the
// Content Security Policy allows scripts from this origin only.
try {
  var t = localStorage.getItem('fahoba-theme')
  if (t === 'light' || t === 'dark') {
    document.documentElement.dataset.theme = t
  }
}
catch (e) {
  document.documentElement.removeAttribute('data-theme')
}

// The design pack last shown, so its colours do not arrive a moment late.
// Only a stylesheet of this server (the same check as utils/design.ts).
try {
  var d = JSON.parse(localStorage.getItem('fahoba-design') || 'null')
  if (d && /^[a-z0-9][a-z0-9-]*\/[a-z0-9][a-z0-9-]*$/.test(d.key) && /^\/api\/design\/[a-z0-9][a-z0-9-]*\/[a-z0-9][a-z0-9-]*\/[0-9a-f]{12}\.css$/.test(d.url)) {
    var l = document.createElement('link')
    l.id = 'fahoba-design'
    l.rel = 'stylesheet'
    l.href = d.url
    document.head.appendChild(l)
    document.documentElement.dataset.design = d.key
  }
}
catch (e) {
  document.documentElement.removeAttribute('data-design')
}
