/* /gestion_vial_ui/js/offline_v2.js
   OfflineCronicasV2: guarda crónicas offline (incluye adjuntos) y sincroniza cuando vuelve internet.
   Incluye diagnóstico visible (alert) cuando falla el sync.
*/
class OfflineCronicasV2 {
  constructor(opts = {}) {
    this.dbName = opts.dbName || "GestionVialOfflineV2";

    // IMPORTANTE: subir versión para forzar upgrade si ya existía con esquema viejo
    this.dbVersion = opts.dbVersion || 3;

    this.storeQueue = "queue";
    this.storeCache = "cache";

    this.syncUrl = opts.syncUrl || "/gestion_vial_ui/api/cronicas_sync_v2.php";
    this.cacheUrl = opts.cacheUrl || "/gestion_vial_ui/api/cronicas_cache_v2.php";

    this.db = null;
    this.online = navigator.onLine;
    this.syncing = false;

    this.showSyncErrors = (opts.showSyncErrors !== undefined) ? !!opts.showSyncErrors : true;

    this.init();
  }

  async init() {
    await this.openDB();

    window.addEventListener("online", async () => {
      this.online = true;
      await this.refreshCacheIfOnline();
      await this.syncNow();
    });

    window.addEventListener("offline", () => {
      this.online = false;
    });

    // al cargar: si hay internet, sincroniza de una vez
    try {
      const ok = await this.checkOnline();
      this.online = ok;
      if (ok) {
        await this.refreshCacheIfOnline();
        await this.syncNow();
      }
    } catch {}

    // chequeo periódico
    setInterval(async () => {
      const ok = await this.checkOnline();
      this.online = ok;
      if (ok) {
        await this.refreshCacheIfOnline();
        await this.syncNow();
      }
    }, 25000);

    await this.refreshCacheIfOnline();
  }

  async openDB() {
    if (this.db) return this.db;

    this.db = await new Promise((resolve, reject) => {
      const req = indexedDB.open(this.dbName, this.dbVersion);

      req.onupgradeneeded = () => {
        const db = req.result;

        // RECREAR stores para evitar esquemas viejos con keyPath incorrecto
        if (db.objectStoreNames.contains(this.storeQueue)) {
          db.deleteObjectStore(this.storeQueue);
        }
        const st = db.createObjectStore(this.storeQueue, { keyPath: "id" });
        st.createIndex("estado", "estado", { unique: false });
        st.createIndex("createdAt", "createdAt", { unique: false });

        if (db.objectStoreNames.contains(this.storeCache)) {
          db.deleteObjectStore(this.storeCache);
        }
        db.createObjectStore(this.storeCache, { keyPath: "key" });
      };

      req.onsuccess = () => resolve(req.result);
      req.onerror = () => reject(req.error);
    });

    return this.db;
  }

  tx(storeName, mode = "readonly") {
    const tx = this.db.transaction([storeName], mode);
    return tx.objectStore(storeName);
  }

  async put(storeName, value) {
    await this.openDB();

    // Validación fuerte para evitar el error que estás viendo
    if (storeName === this.storeQueue) {
      if (!value || typeof value !== "object") throw new Error("Registro inválido para queue");
      if (!value.id || String(value.id).trim() === "") throw new Error("Registro sin id (keyPath)");
    }
    if (storeName === this.storeCache) {
      if (!value || typeof value !== "object") throw new Error("Registro inválido para cache");
      if (!value.key || String(value.key).trim() === "") throw new Error("Registro sin key (keyPath)");
    }

    return new Promise((resolve, reject) => {
      const st = this.tx(storeName, "readwrite");
      const req = st.put(value);
      req.onsuccess = () => resolve(true);
      req.onerror = () => reject(req.error);
    });
  }

  async get(storeName, key) {
    await this.openDB();
    return new Promise((resolve, reject) => {
      const st = this.tx(storeName, "readonly");
      const req = st.get(key);
      req.onsuccess = () => resolve(req.result || null);
      req.onerror = () => reject(req.error);
    });
  }

  async del(storeName, key) {
    await this.openDB();
    return new Promise((resolve, reject) => {
      const st = this.tx(storeName, "readwrite");
      const req = st.delete(key);
      req.onsuccess = () => resolve(true);
      req.onerror = () => reject(req.error);
    });
  }

  async getAll(storeName) {
    await this.openDB();
    return new Promise((resolve, reject) => {
      const st = this.tx(storeName, "readonly");
      const req = st.getAll();
      req.onsuccess = () => resolve(req.result || []);
      req.onerror = () => reject(req.error);
    });
  }

  // opcional: por si querés resetear manualmente desde consola
  async resetDB() {
    if (this.db) {
      try { this.db.close(); } catch {}
      this.db = null;
    }
    await new Promise((resolve, reject) => {
      const req = indexedDB.deleteDatabase(this.dbName);
      req.onsuccess = () => resolve(true);
      req.onerror = () => reject(req.error);
      req.onblocked = () => reject(new Error("deleteDatabase bloqueado: cerrá pestañas abiertas del sistema"));
    });
    await this.openDB();
  }

  async checkOnline() {
    try {
      const r = await fetch("/gestion_vial_ui/api/check_online_v2.PHP", { cache: "no-store" });
      if (!r.ok) return navigator.onLine;
      const j = await r.json();
      return !!j.online;
    } catch {
      return navigator.onLine;
    }
  }

  async refreshCacheIfOnline() {
    if (!navigator.onLine) return;
    try {
      const r = await fetch(this.cacheUrl, { cache: "no-store" });
      if (!r.ok) return;
      const j = await r.json();
      if (!j || !j.success) return;
      await this.put(this.storeCache, { key: "cronicas_cache", value: j, savedAt: Date.now() });
    } catch {
      // silencioso
    }
  }

  // ==========================
  // Guardar crónica offline
  // ==========================
  async saveOffline(form, editorInstance = null) {
    if (!form) throw new Error("Formulario inválido");

    const payload = this.buildPayloadFromForm(form, editorInstance);
    const files = await this.extractFilesFromForm(form);

    const id =
      (window.crypto && typeof window.crypto.randomUUID === "function")
        ? window.crypto.randomUUID()
        : (String(Date.now()) + "_" + Math.random().toString(16).slice(2));

    if (!id) throw new Error("No se pudo generar id para offline");

    const record = {
      id,
      estado: "pendiente",
      createdAt: Date.now(),
      payload,
      files
    };

    await this.put(this.storeQueue, record);
    return record;
  }

  buildPayloadFromForm(form, editorInstance) {
    const fd = new FormData(form);

    let comentarios = "";
    if (editorInstance && typeof editorInstance.getData === "function") {
      comentarios = (editorInstance.getData() || "").trim();
    } else {
      comentarios = (fd.get("comentarios") || "").toString().trim();
    }

    const tipo_ids = fd.getAll("tipo_id[]").map(x => parseInt(x, 10)).filter(x => x > 0);

    const imagenes_desc = {};
    const descInputs = form.querySelectorAll('input[name^="imagenes_desc["]');
    descInputs.forEach((el) => {
      const name = el.getAttribute("name") || "";
      const m = /^imagenes_desc\[(\d+)\]$/.exec(name);
      if (!m) return;
      imagenes_desc[m[1]] = (el.value || "").toString().trim();
    });

    return {
      proyecto_id: parseInt(fd.get("proyecto_id") || "0", 10) || 0,
      encargado_id: parseInt(fd.get("encargado_id") || "0", 10) || 0,
      distrito_id: parseInt(fd.get("distrito_id") || "0", 10) || 0,
      estado: (fd.get("estado") || "Pendiente").toString(),
      comentarios,
      observaciones: (fd.get("observaciones") || "").toString(),
      tipo_ids,
      imagenes_desc
    };
  }

  async extractFilesFromForm(form) {
    const pickFileInput = (id, nameBase) => {
      let el = form.querySelector(`#${id}`);
      if (el && el.type === "file") return el;

      el = form.querySelector(`input[type="file"][name="${nameBase}[]"]`);
      if (el) return el;

      el = form.querySelector(`input[type="file"][name="${nameBase}"]`);
      if (el) return el;

      return null;
    };

    const inputEvid = pickFileInput("imagenesInput", "imagenes");
    const inputAdj  = pickFileInput("adjuntosInput", "adjuntos_img");
    const inputDocs = pickFileInput("documentosInput", "documentos");
    const inputFirm = pickFileInput("firmadosInput", "firmados");

    const toStored = (file) => {
      const blob = file.slice(0, file.size, file.type);
      return { name: file.name, type: file.type, size: file.size, blob };
    };

    const listFrom = async (input) => {
      const arr = [];
      if (!input || !input.files) return arr;
      for (const f of Array.from(input.files)) {
        if (f && f.size > 0) arr.push(toStored(f));
      }
      return arr;
    };

    return {
      imagenes: await listFrom(inputEvid),
      adjuntos_img: await listFrom(inputAdj),
      documentos: await listFrom(inputDocs),
      firmados: await listFrom(inputFirm)
    };
  }

  // ==========================
  // UI para mostrar error
  // ==========================
  showSyncError(message) {
    if (!this.showSyncErrors) return;
    alert(message);
  }

  formatServerError(status, rawText, jsonObj) {
    const lines = [];
    lines.push("Error sincronizando crónica offline");
    lines.push("");
    lines.push("HTTP Status: " + status);

    if (jsonObj) {
      if (jsonObj.error) lines.push("Servidor error: " + jsonObj.error);
      if (jsonObj.warnings && Object.keys(jsonObj.warnings).length) {
        lines.push("");
        lines.push("Warnings:");
        lines.push(JSON.stringify(jsonObj.warnings, null, 2));
      }
      if (jsonObj.debug) {
        lines.push("");
        lines.push("Debug:");
        lines.push(JSON.stringify(jsonObj.debug, null, 2));
      }
    } else {
      lines.push("");
      lines.push("Respuesta RAW:");
      lines.push(rawText ? rawText.slice(0, 2000) : "(vacía)");
    }

    return lines.join("\n");
  }

  // ==========================
  // Sincronización
  // ==========================
  async syncNow() {
    if (this.syncing) return;
    if (!navigator.onLine) return;

    this.syncing = true;
    try {
      const all = await this.getAll(this.storeQueue);
      const pendientes = all
        .filter(x => x && x.estado === "pendiente")
        .sort((a, b) => (a.createdAt || 0) - (b.createdAt || 0));

      for (const item of pendientes) {
        const ok = await this.syncOne(item);
        if (ok) {
          await this.del(this.storeQueue, item.id);
        } else {
          item.estado = "error";
          item.lastErrorAt = Date.now();
          await this.put(this.storeQueue, item);
        }
      }
    } finally {
      this.syncing = false;
    }
  }

  async syncOne(item) {
    let res = null;
    let rawText = "";
    try {
      const fd = new FormData();
      fd.append("payload", JSON.stringify(item.payload || {}));

      const nImgs = (item.files?.imagenes || []).length;
      const nAdj  = (item.files?.adjuntos_img || []).length;
      const nDocs = (item.files?.documentos || []).length;
      const nFirm = (item.files?.firmados || []).length;

      console.log("OFFLINE SYNC SEND:", { nImgs, nAdj, nDocs, nFirm, payload: item.payload });

      for (const f of (item.files?.imagenes || [])) fd.append("imagenes[]", f.blob, f.name);
      for (const f of (item.files?.adjuntos_img || [])) fd.append("adjuntos_img[]", f.blob, f.name);
      for (const f of (item.files?.documentos || [])) fd.append("documentos[]", f.blob, f.name);
      for (const f of (item.files?.firmados || [])) fd.append("firmados[]", f.blob, f.name);

      res = await fetch(this.syncUrl, { method: "POST", body: fd });
      rawText = await res.text();

      let j = null;
      try { j = JSON.parse(rawText); } catch {}

      console.log("OFFLINE SYNC STATUS:", res.status);
      console.log("OFFLINE SYNC RAW:", rawText);
      console.log("OFFLINE SYNC JSON:", j);

      const ok = (res.ok && j && j.success);
      if (!ok) {
        const msg = this.formatServerError(res.status, rawText, j);
        this.showSyncError(msg);
      }

      return ok;
    } catch (e) {
      const status = res ? res.status : 0;
      const msg = [
        "Error sincronizando crónica offline",
        "",
        "HTTP Status: " + status,
        "Excepción JS: " + (e && e.message ? e.message : String(e)),
        "",
        "Respuesta RAW (si existió):",
        rawText ? rawText.slice(0, 2000) : "(no hay)"
      ].join("\n");

      console.log("OFFLINE SYNC ERROR:", e);
      this.showSyncError(msg);
      return false;
    }
  }

  async renderOfflineRow(record) {
    return record;
  }
}

window.offlineCronicasV2 = new OfflineCronicasV2({
  syncUrl: "/gestion_vial_ui/api/cronicas_sync_v2.php",
  cacheUrl: "/gestion_vial_ui/api/cronicas_cache_v2.php",
  showSyncErrors: true
});
window.offlineSystem = window.offlineCronicasV2;
