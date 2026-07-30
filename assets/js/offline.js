const DB_NAME = 'AgroPlannerOfflineDB';
const STORE_NAME = 'pendingRequests';

function openDB() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, 1);
        request.onupgradeneeded = function(e) {
            const db = e.target.result;
            if (!db.objectStoreNames.contains(STORE_NAME)) {
                db.createObjectStore(STORE_NAME, { keyPath: 'id', autoIncrement: true });
            }
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

function saveRequest(url, method, formData) {
    return openDB().then(db => {
        return new Promise((resolve, reject) => {
            const tx = db.transaction(STORE_NAME, 'readwrite');
            const store = tx.objectStore(STORE_NAME);
            
            const entries = {};
            for (let [key, value] of formData.entries()) {
                entries[key] = value;
            }
            
            store.add({
                url,
                method,
                body: entries,
                timestamp: Date.now()
            });
            
            tx.oncomplete = () => resolve();
            tx.onerror = () => reject(tx.error);
        });
    });
}

function getPendingRequests() {
    return openDB().then(db => {
        return new Promise((resolve, reject) => {
            const tx = db.transaction(STORE_NAME, 'readonly');
            const store = tx.objectStore(STORE_NAME);
            const req = store.getAll();
            req.onsuccess = () => resolve(req.result);
            req.onerror = () => reject(req.error);
        });
    });
}

function deleteRequest(id) {
    return openDB().then(db => {
        return new Promise((resolve, reject) => {
            const tx = db.transaction(STORE_NAME, 'readwrite');
            const store = tx.objectStore(STORE_NAME);
            store.delete(id);
            tx.oncomplete = () => resolve();
            tx.onerror = () => reject(tx.error);
        });
    });
}

document.addEventListener('DOMContentLoaded', () => {
    // Inyectar estilos globales para toasts y badge offline
    if (!document.getElementById('offline-styles')) {
        const style = document.createElement('style');
        style.id = 'offline-styles';
        style.innerHTML = `
            @keyframes slideIn { to { opacity: 1; transform: translateY(0); } }
            @keyframes fadeOut { to { opacity: 0; transform: translateY(100%); } }
            @keyframes pulse-dot { 
                0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7); } 
                70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(239, 68, 68, 0); } 
                100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); } 
            }
            .offline-badge {
                display: none;
                align-items: center;
                gap: 8px;
                padding: 6px 14px;
                border-radius: 50px;
                font-size: 0.85rem;
                font-weight: 600;
                letter-spacing: 0.3px;
                backdrop-filter: blur(10px);
                -webkit-backdrop-filter: blur(10px);
                border: 1px solid rgba(255, 255, 255, 0.1);
                box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
                transition: all 0.3s ease;
                white-space: nowrap;
                z-index: 9000;
            }
            .offline-badge.danger { background: rgba(239, 68, 68, 0.15); color: #fca5a5; border-color: rgba(239, 68, 68, 0.3); }
            .offline-badge.warning { background: rgba(245, 158, 11, 0.15); color: #fcd34d; border-color: rgba(245, 158, 11, 0.3); }
            .offline-badge.success { background: rgba(16, 185, 129, 0.15); color: #6ee7b7; border-color: rgba(16, 185, 129, 0.3); }
            .status-dot {
                width: 8px;
                height: 8px;
                border-radius: 50%;
                display: inline-block;
            }
            .status-dot.danger { background-color: #ef4444; animation: pulse-dot 2s infinite; }
            .status-dot.warning { background-color: #f59e0b; }
            .status-dot.success { background-color: #10b981; }
        `;
        document.head.appendChild(style);
    }

    updateNetworkStatus();

    window.addEventListener('online', () => {
        updateNetworkStatus();
        syncPendingRequests();
    });

    window.addEventListener('offline', updateNetworkStatus);

    document.addEventListener('submit', async (e) => {
        if (!navigator.onLine) {
            e.preventDefault(); // Prevent standard sync submission
            
            // Si el formulario indica explícitamente que ignoremos offline
            if (e.target.dataset.ignoreOffline === "true") return;

            const form = e.target;
            const formData = new FormData(form);
            // Formatear url quitando parámetros extra en GET si es necesario, pero acá es POST
            let url = form.action || window.location.href;
            const method = form.method || 'POST';

            try {
                await saveRequest(url, method, formData);
                showToast('Desconectado. Datos guardados localmente para sincronizar luego.', 'warning');
                
                // Si existe closeModal, cerramos el modal actual
                if (typeof window.closeModal === 'function') {
                    window.closeModal();
                }
                updateNetworkStatus();
            } catch (err) {
                console.error("Error guardando request offline", err);
                showToast('Error al guardar datos offline', 'danger');
            }
        }
    });
});

async function syncPendingRequests() {
    const requests = await getPendingRequests();
    if (requests.length === 0) return;

    showToast(`Sincronizando ${requests.length} cambios pendientes...`, 'info');
    
    let synced = 0;
    for (const req of requests) {
        try {
            const formData = new FormData();
            for (let key in req.body) {
                formData.append(key, req.body[key]);
            }
            
            const response = await fetch(req.url, {
                method: req.method,
                body: formData
            });

            if (response.ok || response.redirected) {
                await deleteRequest(req.id);
                synced++;
            }
        } catch (err) {
            console.error("Error sincronizando request", err);
        }
    }

    if (synced > 0) {
        showToast(`Se han sincronizado ${synced} cambios.`, 'success');
        updateNetworkStatus();
        setTimeout(() => window.location.reload(), 1500);
    }
}

function updateNetworkStatus() {
    const offlineBanner = document.getElementById('offline-status-banner');
    if (!offlineBanner) return;

    if (!navigator.onLine) {
        getPendingRequests().then(reqs => {
            const count = reqs.length;
            if (count > 0) {
                offlineBanner.className = 'offline-badge warning';
                offlineBanner.innerHTML = `<span class="status-dot warning"></span><i class="fas fa-wifi" style="opacity:0.7"></i> ${count} pendientes`;
            } else {
                offlineBanner.className = 'offline-badge danger';
                offlineBanner.innerHTML = `<span class="status-dot danger"></span><i class="fas fa-wifi" style="opacity:0.7"></i> Offline`;
            }
            offlineBanner.style.display = 'flex';
        });
    } else {
        getPendingRequests().then(reqs => {
            if (reqs.length > 0) {
                offlineBanner.className = 'offline-badge success';
                offlineBanner.innerHTML = `<span class="status-dot success"></span><i class="fas fa-sync fa-spin"></i> Sincronizando...`;
                offlineBanner.style.display = 'flex';
            } else {
                offlineBanner.style.display = 'none';
            }
        });
    }
}

function showToast(message, type = 'info') {
    let container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        container.style.position = 'fixed';
        container.style.bottom = '20px';
        container.style.right = '20px';
        container.style.zIndex = '9999';
        container.style.display = 'flex';
        container.style.flexDirection = 'column';
        container.style.gap = '10px';
        document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    
    const bgColors = {
        'success': '#10b981',
        'danger': '#ef4444',
        'warning': '#f59e0b',
        'info': '#3b82f6'
    };
    
    toast.style.background = bgColors[type] || bgColors['info'];
    toast.style.color = type === 'warning' ? '#333' : 'white';
    toast.style.padding = '12px 20px';
    toast.style.borderRadius = '8px';
    toast.style.boxShadow = '0 4px 6px rgba(0,0,0,0.1)';
    toast.style.display = 'flex';
    toast.style.alignItems = 'center';
    toast.style.gap = '10px';
    toast.style.animation = 'slideIn 0.3s ease-out forwards';
    toast.style.opacity = '0';
    toast.style.transform = 'translateY(100%)';
    toast.style.fontFamily = "'Inter', sans-serif";
    toast.style.fontSize = "0.9rem";
    
    toast.innerHTML = `<i class="fas ${type === 'warning' ? 'fa-exclamation-triangle' : type === 'success' ? 'fa-check-circle' : type === 'danger' ? 'fa-times-circle' : 'fa-info-circle'}"></i> ` + message;

    container.appendChild(toast);

    setTimeout(() => {
        toast.style.animation = 'fadeOut 0.3s ease-in forwards';
        setTimeout(() => toast.remove(), 300);
    }, 4000);
}
