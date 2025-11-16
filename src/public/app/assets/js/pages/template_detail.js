// src/public/app/assets/js/pages/template_detail.js
import { render } from '../view.js';
import { api } from '../Remote.js';

let templateId = null;
let users = [];
let tomSelectInstances = new Map(); // Map of elementId -> TomSelect instance
let saveDebounceTimers = new Map(); // Map of itemId -> debounce timer

async function load(id) {
  await render('template_detail', { loading: true, template: {}, items: [] });

  try {
    const res = await api(`/api/gift-templates/${id}`);
    const data = res?.data || res;
    const template = data.template || {};
    const items = data.items || [];

    await render('template_detail', { loading: false, template, items });

    // Load users and initialize selects
    await loadUsers();
    initializeAllSelects(items);
  } catch (err) {
    console.error('[template_detail] load error', err);
    await render('template_detail', { loading: false, template: {}, items: [], error: err.message });
  }
}

async function loadUsers() {
  try {
    const res = await api('/api/users');
    users = res?.data?.users || res?.users || [];
  } catch (err) {
    console.error('[template_detail] failed to load users', err);
    users = [];
  }
}

function initializeAllSelects(items) {
  // Check if TomSelect is available
  if (typeof TomSelect === 'undefined') {
    console.error('[template_detail] TomSelect library not loaded!');
    return;
  }

  // Cleanup existing selects
  destroyAllSelects();

  if (users.length === 0) {
    console.error('[template_detail] No users loaded');
    return;
  }

  const options = users.map(u => ({
    value: u.id,
    text: u.display_name,
    avatar: u.profile_image_url || null
  }));

  const renderOption = function(data, escape) {
    let avatar = '';
    if (data.avatar) {
      avatar = `<img src="${escape(data.avatar)}" class="ts-avatar" alt="${escape(data.text)}">`;
    } else {
      const initials = data.text.slice(0, 2).toUpperCase();
      avatar = `<span class="ts-avatar--initials">${escape(initials)}</span>`;
    }
    return `<div>${avatar} <span>${escape(data.text)}</span></div>`;
  };

  const renderItem = function(data, escape) {
    let avatar = '';
    if (data.avatar) {
      avatar = `<img src="${escape(data.avatar)}" class="ts-avatar" alt="${escape(data.text)}">`;
    } else {
      const initials = data.text.slice(0, 2).toUpperCase();
      avatar = `<span class="ts-avatar--initials">${escape(initials)}</span>`;
    }
    return `<div>${avatar} <span>${escape(data.text)}</span></div>`;
  };

  console.log('[template_detail] Initializing TomSelect for all rows');

  // Initialize selects for existing items
  items.forEach(item => {
    const giverEl = document.getElementById(`giverSelect_${item.id}`);
    const recipientEl = document.getElementById(`recipientSelect_${item.id}`);

    if (!giverEl || !recipientEl) {
      console.error(`[template_detail] Select elements not found for item ${item.id}`);
      return;
    }

    // Initialize giver select
    try {
      const giverSelect = new TomSelect(`#giverSelect_${item.id}`, {
        options: options,
        items: item.givers.map(g => g.user_id),
        valueField: 'value',
        labelField: 'text',
        searchField: 'text',
        dropdownParent: 'body',
        plugins: ['remove_button'],
        maxItems: null,
        render: {
          option: renderOption,
          item: renderItem
        },
        onItemAdd: function() {
          this.setTextboxValue('');
          this.refreshOptions();
        },
        onBlur: () => handleRowChange(item.id)
      });
      tomSelectInstances.set(`giverSelect_${item.id}`, giverSelect);
    } catch (e) {
      console.error(`[template_detail] Error creating giver select for item ${item.id}:`, e);
    }

    // Initialize recipient select
    try {
      const recipientSelect = new TomSelect(`#recipientSelect_${item.id}`, {
        options: options,
        items: item.recipients.map(r => r.user_id),
        valueField: 'value',
        labelField: 'text',
        searchField: 'text',
        dropdownParent: 'body',
        plugins: ['remove_button'],
        maxItems: null,
        render: {
          option: renderOption,
          item: renderItem
        },
        onItemAdd: function() {
          this.setTextboxValue('');
          this.refreshOptions();
        },
        onBlur: () => handleRowChange(item.id)
      });
      tomSelectInstances.set(`recipientSelect_${item.id}`, recipientSelect);
    } catch (e) {
      console.error(`[template_detail] Error creating recipient select for item ${item.id}:`, e);
    }
  });

  // Initialize selects for new row
  const newGiverEl = document.getElementById('newGiverSelect');
  const newRecipientEl = document.getElementById('newRecipientSelect');

  if (newGiverEl && newRecipientEl) {
    try {
      const newGiverSelect = new TomSelect('#newGiverSelect', {
        options: options,
        valueField: 'value',
        labelField: 'text',
        searchField: 'text',
        dropdownParent: 'body',
        plugins: ['remove_button'],
        maxItems: null,
        render: {
          option: renderOption,
          item: renderItem
        },
        onItemAdd: function() {
          this.setTextboxValue('');
          this.refreshOptions();
        },
        onBlur: () => handleRowChange('new')
      });
      tomSelectInstances.set('newGiverSelect', newGiverSelect);
    } catch (e) {
      console.error('[template_detail] Error creating new giver select:', e);
    }

    try {
      const newRecipientSelect = new TomSelect('#newRecipientSelect', {
        options: options,
        valueField: 'value',
        labelField: 'text',
        searchField: 'text',
        dropdownParent: 'body',
        plugins: ['remove_button'],
        maxItems: null,
        render: {
          option: renderOption,
          item: renderItem
        },
        onItemAdd: function() {
          this.setTextboxValue('');
          this.refreshOptions();
        },
        onBlur: () => handleRowChange('new')
      });
      tomSelectInstances.set('newRecipientSelect', newRecipientSelect);
    } catch (e) {
      console.error('[template_detail] Error creating new recipient select:', e);
    }
  }

  console.log(`[template_detail] Initialized ${tomSelectInstances.size} TomSelect instances`);
}

function destroyAllSelects() {
  console.log(`[template_detail] Destroying ${tomSelectInstances.size} TomSelect instances`);
  tomSelectInstances.forEach((instance, id) => {
    try {
      instance.destroy();
    } catch (e) {
      console.error(`[template_detail] Error destroying select ${id}:`, e);
    }
  });
  tomSelectInstances.clear();
}

async function handleRowChange(itemId) {
  console.log(`[template_detail] handleRowChange called for item: ${itemId}`);

  const isNewRow = itemId === 'new';
  const giverSelectId = isNewRow ? 'newGiverSelect' : `giverSelect_${itemId}`;
  const recipientSelectId = isNewRow ? 'newRecipientSelect' : `recipientSelect_${itemId}`;

  const giverSelect = tomSelectInstances.get(giverSelectId);
  const recipientSelect = tomSelectInstances.get(recipientSelectId);

  if (!giverSelect || !recipientSelect) {
    console.error(`[template_detail] TomSelect instances not found for item ${itemId}`);
    return;
  }

  const giverIds = giverSelect.getValue();
  const recipientIds = recipientSelect.getValue();

  console.log(`[template_detail] Selected values for ${itemId}:`, { giverIds, recipientIds });

  // Only auto-save if both have at least one selection
  if (!giverIds || !recipientIds || giverIds.length === 0 || recipientIds.length === 0) {
    console.log(`[template_detail] Skipping save - incomplete selections`);
    return;
  }

  // Clear any existing debounce timer for this item
  const existingTimer = saveDebounceTimers.get(itemId);
  if (existingTimer) {
    console.log(`[template_detail] Clearing previous debounce timer for item ${itemId}`);
    clearTimeout(existingTimer);
  }

  // Debounce the save briefly to avoid multiple rapid saves
  console.log(`[template_detail] Setting debounce timer (300ms) for item ${itemId}`);
  const timer = setTimeout(async () => {
    console.log(`[template_detail] Debounce timer fired for item ${itemId}, proceeding with save`);
    saveDebounceTimers.delete(itemId);

    // Validate no overlap
    const overlap = giverIds.filter(id => recipientIds.includes(id));
    if (overlap.length > 0) {
      alert('Same person cannot be both giver and recipient');
      return;
    }

    try {
      if (isNewRow) {
        // Create new item
        console.log('[template_detail] Creating new relationship...');
        const res = await api(`/api/gift-templates/${templateId}/items`, {
          method: 'POST',
          body: {
            giver_ids: giverIds,
            recipient_ids: recipientIds,
            notes: null
          }
        });

        const data = res?.data || res;
        const newItemId = data?.item_id || data?.id;
        console.log('[template_detail] Created with ID:', newItemId);

        // Add new row to table instead of reloading
        await addNewRowToTable(newItemId, giverIds, recipientIds);

        // Clear the "new" row selects
        giverSelect.clear();
        recipientSelect.clear();

        // Focus on giver field to continue adding
        giverSelect.focus();
      } else {
        // Update existing item
        console.log(`[template_detail] Updating item ${itemId}...`);
        await api(`/api/gift-templates/${templateId}/items/${itemId}`, {
          method: 'PATCH',
          body: {
            giver_ids: giverIds,
            recipient_ids: recipientIds
          }
        });
        console.log(`[template_detail] Item ${itemId} updated successfully`);
      }
    } catch (err) {
      console.error('[template_detail] save error', err);
      alert('Failed to save relationship: ' + (err.message || 'Unknown error'));
      // Reload to reset state
      await load(templateId);
    }
  }, 300);

  saveDebounceTimers.set(itemId, timer);
}

async function addNewRowToTable(itemId, giverIds, recipientIds) {
  console.log('[template_detail] Adding new row to table for item:', itemId);

  // Get user options for TomSelect
  const options = users.map(u => ({
    value: u.id,
    text: u.display_name,
    avatar: u.profile_image_url || null
  }));

  const renderOption = function(data, escape) {
    let avatar = '';
    if (data.avatar) {
      avatar = `<img src="${escape(data.avatar)}" class="ts-avatar" alt="${escape(data.text)}">`;
    } else {
      const initials = data.text.slice(0, 2).toUpperCase();
      avatar = `<span class="ts-avatar--initials">${escape(initials)}</span>`;
    }
    return `<div>${avatar} <span>${escape(data.text)}</span></div>`;
  };

  const renderItem = function(data, escape) {
    let avatar = '';
    if (data.avatar) {
      avatar = `<img src="${escape(data.avatar)}" class="ts-avatar" alt="${escape(data.text)}">`;
    } else {
      const initials = data.text.slice(0, 2).toUpperCase();
      avatar = `<span class="ts-avatar--initials">${escape(initials)}</span>`;
    }
    return `<div>${avatar} <span>${escape(data.text)}</span></div>`;
  };

  // Create the new row element
  const newRow = document.createElement('tr');
  newRow.setAttribute('data-item-id', itemId);
  newRow.className = 'template-row-saved';
  newRow.innerHTML = `
    <td>
      <div class="d-flex gap-1 align-items-start">
        <div class="flex-fill">
          <select id="giverSelect_${itemId}" class="row-select" data-item-id="${itemId}" data-role="giver"></select>
        </div>
        <div class="d-flex gap-1 align-items-center">
          <i
            class="fa-solid fa-arrow-down text-secondary"
            data-copy-above="${itemId}"
            data-role="giver"
            title="Copy from above"
            style="cursor: pointer; font-size: 0.75rem;"
          ></i>
          <i
            class="fa-solid fa-users text-secondary"
            data-add-family="${itemId}"
            title="Add entire family"
            style="cursor: pointer; font-size: 0.75rem;"
          ></i>
          <i
            class="fa-solid fa-xmark text-secondary"
            data-clear-cell="${itemId}"
            data-role="giver"
            title="Clear all"
            style="cursor: pointer; font-size: 0.75rem;"
          ></i>
        </div>
      </div>
    </td>
    <td>
      <div class="d-flex gap-1 align-items-start">
        <div class="flex-fill">
          <select id="recipientSelect_${itemId}" class="row-select" data-item-id="${itemId}" data-role="recipient"></select>
        </div>
        <div class="d-flex gap-1 align-items-center">
          <i
            class="fa-solid fa-arrow-down text-secondary"
            data-copy-above="${itemId}"
            data-role="recipient"
            title="Copy from above"
            style="cursor: pointer; font-size: 0.75rem;"
          ></i>
          <i
            class="fa-solid fa-xmark text-secondary"
            data-clear-cell="${itemId}"
            data-role="recipient"
            title="Clear all"
            style="cursor: pointer; font-size: 0.75rem;"
          ></i>
        </div>
      </div>
    </td>
    <td class="text-center">
      <button
        class="btn-icon btn-icon--sm btn-icon--danger"
        data-delete-item="${itemId}"
        title="Delete"
        type="button"
      >
        <i class="fa-solid fa-trash"></i>
      </button>
    </td>
  `;

  // Insert the new row before the "new" row
  const newRowElement = document.getElementById('newRow');
  newRowElement.parentNode.insertBefore(newRow, newRowElement);

  // Initialize TomSelect for the new row
  try {
    const giverSelect = new TomSelect(`#giverSelect_${itemId}`, {
      options: options,
      items: giverIds,
      valueField: 'value',
      labelField: 'text',
      searchField: 'text',
      dropdownParent: 'body',
      plugins: ['remove_button'],
      maxItems: null,
      render: {
        option: renderOption,
        item: renderItem
      },
      onItemAdd: function() {
        this.setTextboxValue('');
        this.refreshOptions();
      },
      onBlur: () => handleRowChange(itemId)
    });
    tomSelectInstances.set(`giverSelect_${itemId}`, giverSelect);

    const recipientSelect = new TomSelect(`#recipientSelect_${itemId}`, {
      options: options,
      items: recipientIds,
      valueField: 'value',
      labelField: 'text',
      searchField: 'text',
      dropdownParent: 'body',
      plugins: ['remove_button'],
      maxItems: null,
      render: {
        option: renderOption,
        item: renderItem
      },
      onItemAdd: function() {
        this.setTextboxValue('');
        this.refreshOptions();
      },
      onBlur: () => handleRowChange(itemId)
    });
    tomSelectInstances.set(`recipientSelect_${itemId}`, recipientSelect);

    console.log('[template_detail] TomSelect initialized for new row');
  } catch (e) {
    console.error('[template_detail] Error initializing TomSelect for new row:', e);
  }
}

function bindActions() {
  const root = document.getElementById('app');
  if (!root) {
    console.error('[template_detail] bindActions: root element not found');
    return () => {};
  }

  console.log('[template_detail] bindActions: Binding click handler to root');

  const handler = async (e) => {
    // Handle "Copy from Above" button
    const copyAboveBtn = e.target.closest('[data-copy-above]');
    if (copyAboveBtn) {
      const itemId = copyAboveBtn.getAttribute('data-copy-above');
      const role = copyAboveBtn.getAttribute('data-role');
      console.log(`[template_detail] Copy from above clicked for item: ${itemId}, role: ${role}`);

      // Find the current row
      const currentRow = copyAboveBtn.closest('tr');
      const previousRow = currentRow.previousElementSibling;

      if (!previousRow || !previousRow.hasAttribute('data-item-id')) {
        console.log('[template_detail] No row above to copy from');
        return;
      }

      const previousItemId = previousRow.getAttribute('data-item-id');
      const currentSelectId = itemId === 'new'
        ? (role === 'giver' ? 'newGiverSelect' : 'newRecipientSelect')
        : (role === 'giver' ? `giverSelect_${itemId}` : `recipientSelect_${itemId}`);

      const previousSelectId = previousItemId === 'new'
        ? (role === 'giver' ? 'newGiverSelect' : 'newRecipientSelect')
        : (role === 'giver' ? `giverSelect_${previousItemId}` : `recipientSelect_${previousItemId}`);

      const currentSelect = tomSelectInstances.get(currentSelectId);
      const previousSelect = tomSelectInstances.get(previousSelectId);

      if (currentSelect && previousSelect) {
        const valuesToCopy = previousSelect.getValue();
        currentSelect.setValue(valuesToCopy);
        console.log(`[template_detail] Copied ${valuesToCopy.length} values from above`);
      }
      return;
    }

    // Handle "Clear Cell" button
    const clearCellBtn = e.target.closest('[data-clear-cell]');
    if (clearCellBtn) {
      const itemId = clearCellBtn.getAttribute('data-clear-cell');
      const role = clearCellBtn.getAttribute('data-role');
      console.log(`[template_detail] Clear cell clicked for item: ${itemId}, role: ${role}`);

      const selectId = itemId === 'new'
        ? (role === 'giver' ? 'newGiverSelect' : 'newRecipientSelect')
        : (role === 'giver' ? `giverSelect_${itemId}` : `recipientSelect_${itemId}`);

      const select = tomSelectInstances.get(selectId);
      if (select) {
        select.clear();
        console.log('[template_detail] Cell cleared');
      }
      return;
    }

    // Handle "Add Family" button
    const addFamilyBtn = e.target.closest('[data-add-family]');
    if (addFamilyBtn) {
      const itemId = addFamilyBtn.getAttribute('data-add-family');
      console.log(`[template_detail] Add family button clicked for item: ${itemId}`);

      const giverSelectId = itemId === 'new' ? 'newGiverSelect' : `giverSelect_${itemId}`;
      const giverSelect = tomSelectInstances.get(giverSelectId);

      if (giverSelect) {
        // Get only family members (is_family_member = true)
        const familyMemberIds = users
          .filter(u => u.is_family_member === true)
          .map(u => u.id);

        console.log(`[template_detail] Adding ${familyMemberIds.length} family members to givers (filtered from ${users.length} total users)`);

        // Set family members as selected
        giverSelect.setValue(familyMemberIds);
        console.log('[template_detail] Family members added successfully');
      } else {
        console.error(`[template_detail] Giver select not found for item ${itemId}`);
      }
      return;
    }

    // Handle delete button
    const delBtn = e.target.closest('[data-delete-item]');
    if (delBtn) {
      console.log('[template_detail] Delete button clicked');
      const itemId = delBtn.getAttribute('data-delete-item');
      console.log('[template_detail] Item ID to delete:', itemId);

      try {
        console.log('[template_detail] Deleting item...');
        await api(`/api/gift-templates/${templateId}/items/${itemId}`, {
          method: 'DELETE'
        });

        // Remove the row from DOM instead of reloading
        const row = document.querySelector(`tr[data-item-id="${itemId}"]`);
        if (row) {
          // Destroy TomSelect instances for this row
          const giverSelect = tomSelectInstances.get(`giverSelect_${itemId}`);
          const recipientSelect = tomSelectInstances.get(`recipientSelect_${itemId}`);

          if (giverSelect) {
            giverSelect.destroy();
            tomSelectInstances.delete(`giverSelect_${itemId}`);
          }
          if (recipientSelect) {
            recipientSelect.destroy();
            tomSelectInstances.delete(`recipientSelect_${itemId}`);
          }

          row.remove();
          console.log('[template_detail] Row removed from DOM');
        }
      } catch (err) {
        console.error('[template_detail] delete error', err);
        alert('Failed to delete relationship');
      }
    }
  };

  root.addEventListener('click', handler);
  console.log('[template_detail] Click handler bound successfully');
  return () => {
    console.log('[template_detail] Unbinding click handler');
    root.removeEventListener('click', handler);
  };
}

let unbindActions = null;

export async function mount(id) {
  console.log('[template_detail] mount called with ID:', id);
  templateId = id;
  document.title = 'Edit Template · MyGifts';
  await load(id);

  // Bind actions after load
  console.log('[template_detail] Binding actions...');
  unbindActions = bindActions();
  console.log('[template_detail] Mount complete');
}

export function unmount() {
  console.log('[template_detail] unmount called');

  if (unbindActions) {
    unbindActions();
    unbindActions = null;
  }

  // Clear all debounce timers
  saveDebounceTimers.forEach((timer, itemId) => {
    console.log(`[template_detail] Clearing debounce timer for item ${itemId}`);
    clearTimeout(timer);
  });
  saveDebounceTimers.clear();

  // Destroy all TomSelect instances
  destroyAllSelects();

  users = [];
  templateId = null;
  console.log('[template_detail] unmount complete');
}
