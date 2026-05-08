(function (ns) {
  ns.registerInitializer(function bindAnimalList(root) {
    const page = root.getElementById('animal-list-page');
    if (!page || page.dataset.listBound === 'true') {
      return;
    }

    page.dataset.listBound = 'true';

    const canUpdateAnimals = page.dataset.canUpdate === 'true';
    const form = root.getElementById('animal-filter-form');
    const tableBody = root.getElementById('animal-table-body');
    const cardList = root.getElementById('animal-card-list');
    const summary = root.getElementById('animal-pagination-summary');
    const controls = root.getElementById('animal-pagination-controls');
    let currentPage = 1;

    if (!form || !tableBody || !cardList || !summary || !controls) {
      return;
    }

    async function load(pageNumber = 1) {
      currentPage = pageNumber;
      const params = new URLSearchParams(new FormData(form));
      params.set('page', String(pageNumber));
      params.set('per_page', '20');

      tableBody.innerHTML = Array(5).fill(`
        <tr>
          <td>
            <div class="animal-cell">
              <div class="animal-cell-media skeleton skeleton-circle"></div>
              <div style="flex:1">
                <div class="skeleton skeleton-text skeleton-lg" style="margin-bottom:4px"></div>
                <div class="skeleton skeleton-text skeleton-sm"></div>
              </div>
            </div>
          </td>
          <td><div class="skeleton skeleton-text"></div></td>
          <td><div class="skeleton skeleton-text skeleton-sm"></div></td>
          <td><div class="skeleton skeleton-text"></div></td>
          <td><div class="skeleton skeleton-text skeleton-sm"></div></td>
          <td><div class="skeleton skeleton-text skeleton-lg"></div></td>
        </tr>
      `).join('');

      cardList.innerHTML = Array(3).fill(`
        <article class="card animal-card">
          <div class="animal-cell">
            <div class="animal-cell-media skeleton skeleton-circle"></div>
            <div style="flex:1">
              <div class="skeleton skeleton-text skeleton-lg" style="margin-bottom:4px"></div>
              <div class="skeleton skeleton-text skeleton-sm"></div>
            </div>
          </div>
          <div class="cluster" style="margin-top:16px; margin-bottom:16px;">
            <div class="skeleton skeleton-text skeleton-sm" style="width:100px;"></div>
            <div class="skeleton skeleton-text skeleton-sm" style="width:80px;"></div>
          </div>
          <div class="animal-actions">
             <div class="skeleton skeleton-rect" style="height:36px; border-radius:18px;"></div>
          </div>
        </article>
      `).join('');

      let result, items, meta;
      try {
        const response = await ns.apiRequest('/api/animals?' + params.toString());
        result = response.data;
        items = Array.isArray(result.data) ? result.data : [];
        meta = result.meta || {};
      } catch (error) {
        tableBody.innerHTML = '';
        cardList.innerHTML = '';
        throw error;
      }

      tableBody.innerHTML = '';
      cardList.innerHTML = '';

      if (items.length === 0) {
        const emptyStateHtml = `
          <div class="empty-state">
            <div class="empty-state-icon">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
              </svg>
            </div>
            <h3 class="empty-state-title">No animals found</h3>
            <p class="empty-state-description">We couldn't find any animals matching your current filters. Try adjusting your search criteria.</p>
            <div class="empty-state-action">
              <button class="btn-secondary" type="button" onclick="document.getElementById('animal-filter-form').reset()">Clear Filters</button>
            </div>
          </div>
        `;

        tableBody.innerHTML = `<tr><td colspan="6">${emptyStateHtml}</td></tr>`;
        cardList.innerHTML = emptyStateHtml;
      }

      items.forEach((animal) => {
        const statusBadge = ns.badgeForStatus(animal.status);
        const media = animal.primary_photo_path
          ? `<img src="/${animal.primary_photo_path}" alt="">`
          : '<span>📷</span>';
        const breed = animal.breed_name || animal.breed_other || 'Unknown';
        const row = document.createElement('tr');
        row.innerHTML = `
          <td>
            <div class="animal-cell">
              <div class="animal-cell-media">${media}</div>
              <div>
                <strong>${ns.escapeHtml(animal.name || 'Unnamed Animal')}</strong><br>
                <span class="text-muted mono">${ns.escapeHtml(animal.animal_id)}</span>
              </div>
            </div>
          </td>
          <td>${ns.escapeHtml(animal.species)} · ${ns.escapeHtml(breed)}</td>
          <td>${ns.escapeHtml(animal.gender)}</td>
          <td><span class="badge ${statusBadge}">${ns.escapeHtml(animal.status)}</span></td>
          <td>${ns.escapeHtml(new Date(animal.intake_date).toLocaleDateString())}</td>
          <td>
            <div class="animal-actions">
              <a class="btn-secondary" href="/animals/${animal.id}">View</a>
              ${canUpdateAnimals ? `<a class="btn-secondary" href="/animals/${animal.id}/edit">Edit</a>` : ''}
              <button class="btn-secondary" type="button" data-qr-preview data-qr-src="/api/animals/${animal.id}/qr" data-qr-name="${ns.escapeHtml(animal.name || 'Unnamed Animal')}" data-qr-code="${ns.escapeHtml(animal.animal_id)}" data-qr-download="/api/animals/${animal.id}/qr/download">QR</button>
            </div>
          </td>
        `;
        tableBody.appendChild(row);

        const card = document.createElement('article');
        card.className = 'card animal-card';
        card.innerHTML = `
          <div class="animal-cell">
            <div class="animal-cell-media">${media}</div>
            <div>
              <strong>${ns.escapeHtml(animal.name || 'Unnamed Animal')}</strong><br>
              <span class="text-muted mono">${ns.escapeHtml(animal.animal_id)}</span>
            </div>
          </div>
          <div class="cluster">
            <span>${ns.escapeHtml(animal.species)} · ${ns.escapeHtml(breed)}</span>
            <span class="badge ${statusBadge}">${ns.escapeHtml(animal.status)}</span>
          </div>
          <div class="animal-actions">
            <a class="btn-secondary" href="/animals/${animal.id}">View</a>
            ${canUpdateAnimals ? `<a class="btn-secondary" href="/animals/${animal.id}/edit">Edit</a>` : ''}
            <button class="btn-secondary" type="button" data-qr-preview data-qr-src="/api/animals/${animal.id}/qr" data-qr-name="${ns.escapeHtml(animal.name || 'Unnamed Animal')}" data-qr-code="${ns.escapeHtml(animal.animal_id)}" data-qr-download="/api/animals/${animal.id}/qr/download">QR</button>
          </div>
        `;
        cardList.appendChild(card);
      });

      const total = meta.total || 0;
      const perPage = meta.per_page || 20;
      const totalPages = meta.total_pages || 1;
      const page = meta.page || pageNumber;
      const start = total === 0 ? 0 : ((page - 1) * perPage) + 1;
      const end = Math.min(total, page * perPage);
      summary.textContent = `Showing ${start}-${end} of ${total}`;

      controls.innerHTML = '';
      const previous = document.createElement('button');
      previous.className = 'btn-secondary';
      previous.type = 'button';
      previous.textContent = 'Previous';
      previous.disabled = currentPage <= 1;
      previous.addEventListener('click', () => load(currentPage - 1));
      controls.appendChild(previous);

      const next = document.createElement('button');
      next.className = 'btn-secondary';
      next.type = 'button';
      next.textContent = 'Next';
      next.disabled = currentPage >= totalPages;
      next.addEventListener('click', () => load(currentPage + 1));
      controls.appendChild(next);
    }

    form.addEventListener('submit', (event) => {
      event.preventDefault();
      load(1);
    });
    form.addEventListener('change', () => load(1));
    form.addEventListener('reset', () => setTimeout(() => load(1), 0));

    load().catch((error) => {
      console.error(error);
      window.toast?.error('Animals load failed', 'Unable to load animal records.');
    });
  });
})(window.CatarmanAnimals);
