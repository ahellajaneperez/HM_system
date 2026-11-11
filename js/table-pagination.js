(() => {
    const pageSizes = [10, 25, 50];
    const pageButtonLimit = 5;

    document.querySelectorAll('table:not([data-pagination="false"])').forEach((table, tableIndex) => {
        const body = table.tBodies[0];
        if (!body) {
            return;
        }

        const rows = Array.from(body.rows).filter((row) => {
            const cell = row.cells[0];
            return !(row.cells.length === 1 && cell && cell.hasAttribute('colspan'));
        });
        const page = document.createElement('nav');
        page.className = 'table-pagination';
        page.setAttribute('aria-label', `Table pagination ${tableIndex + 1}`);

        const info = document.createElement('span');
        info.className = 'table-pagination__info';
        info.setAttribute('aria-live', 'polite');

        const sizeLabel = document.createElement('label');
        sizeLabel.className = 'table-pagination__size';
        sizeLabel.append('Rows per page');

        const sizeSelect = document.createElement('select');
        sizeSelect.setAttribute('aria-label', 'Rows per page');
        pageSizes.forEach((size) => {
            const option = document.createElement('option');
            option.value = String(size);
            option.textContent = String(size);
            sizeSelect.append(option);
        });
        sizeLabel.append(sizeSelect);

        const controls = document.createElement('div');
        controls.className = 'table-pagination__controls';
        const previous = document.createElement('button');
        previous.type = 'button';
        previous.textContent = 'Previous';
        previous.setAttribute('aria-label', 'Previous page');
        const pageButtons = document.createElement('span');
        pageButtons.className = 'table-pagination__controls';
        const next = document.createElement('button');
        next.type = 'button';
        next.textContent = 'Next';
        next.setAttribute('aria-label', 'Next page');
        controls.append(previous, pageButtons, next);
        page.append(info, sizeLabel, controls);

        let currentPage = 1;
        let pageSize = pageSizes[0];

        function render() {
            const pageCount = Math.max(1, Math.ceil(rows.length / pageSize));
            currentPage = Math.min(currentPage, pageCount);
            const start = (currentPage - 1) * pageSize;
            const end = Math.min(start + pageSize, rows.length);

            rows.forEach((row, index) => {
                row.hidden = index < start || index >= end;
            });

            info.textContent = rows.length
                ? `Showing ${start + 1}-${end} of ${rows.length} rows`
                : 'Showing 0 of 0 rows';
            previous.disabled = currentPage === 1;
            next.disabled = currentPage === pageCount;
            pageButtons.replaceChildren();

            const firstPage = Math.max(
                1,
                Math.min(currentPage - Math.floor(pageButtonLimit / 2), pageCount - pageButtonLimit + 1)
            );
            const lastPage = Math.min(pageCount, firstPage + pageButtonLimit - 1);
            for (let number = firstPage; number <= lastPage; number += 1) {
                const button = document.createElement('button');
                button.type = 'button';
                button.textContent = String(number);
                button.setAttribute('aria-label', `Page ${number}`);
                if (number === currentPage) {
                    button.setAttribute('aria-current', 'page');
                }
                button.addEventListener('click', () => {
                    currentPage = number;
                    render();
                });
                pageButtons.append(button);
            }
        }

        previous.addEventListener('click', () => {
            currentPage -= 1;
            render();
        });
        next.addEventListener('click', () => {
            currentPage += 1;
            render();
        });
        sizeSelect.addEventListener('change', () => {
            pageSize = Number(sizeSelect.value);
            currentPage = 1;
            render();
        });

        const tableContainer = table.parentElement.classList.contains('table-wrap')
            ? table.parentElement
            : table;
        tableContainer.insertAdjacentElement('afterend', page);
        render();
    });
})();
