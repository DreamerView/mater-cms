import {
  createApp,
  reactive,
  ref,
  computed,
  onMounted,
  onBeforeUnmount,
  watch,
  nextTick,
} from 'https://cdn.jsdelivr.net/npm/vue@3.5.21/dist/vue.esm-browser.prod.js';

const config = window.__MATERCMS_CONFIG__ || window.__MATER_CONFIG__ || window.__FEATHER_CONFIG__ || {};
const baseUrl = `${String(config.base || '').replace(/\/$/, '')}/`;

const FolderTree = {
  name: 'FolderTree',
  props: {
    folders: { type: Array, required: true },
    documents: { type: Array, required: true },
    parentId: { default: null },
    activeId: { default: null },
    depth: { type: Number, default: 0 },
  },
  emits: ['open'],
  setup(props) {
    const expanded = ref(true);
    const items = computed(() => props.folders.filter(folder => folder.parent_id === props.parentId));
    const count = id => props.folders.filter(folder => folder.parent_id === id).length + props.documents.filter(doc => doc.folder_id === id).length;
    return { expanded, items, count };
  },
  template: `
    <div class="tree-level" :style="{'--depth': depth}">
      <div v-for="folder in items" :key="folder.id" class="tree-branch">
        <div class="tree-row" :class="{active: activeId===folder.id}">
          <button class="tree-toggle" type="button" @click.stop="expanded=!expanded" aria-label="Свернуть"><i class="bi" :class="expanded ? 'bi-chevron-down' : 'bi-chevron-right'"></i></button>
          <button class="tree-link" type="button" @click="$emit('open',folder.id)">
            <i class="bi bi-folder2 tiny-folder-icon"></i><span>{{ folder.name }}</span><small>{{ count(folder.id) }}</small>
          </button>
        </div>
        <folder-tree v-if="expanded" :folders="folders" :documents="documents" :parent-id="folder.id" :active-id="activeId" :depth="depth+1" @open="$emit('open',$event)"></folder-tree>
      </div>
    </div>
  `,
};

const app = createApp({
  setup() {
    const state = reactive({
      user: config.user || null,
      folders: [],
      documents: [],
      content_links: [],
      csrf: config.csrf || '',
      base: config.base || '',
      public_api: config.publicApi || '',
      public_api_absolute: '',
      project_api_namespace: '',
      projects: [],
      current_project: null,
      permissions: {},
      api_access: { mode: 'public', private: false, token_configured: false, token_prefix: '', created_at: null },
      api_status: { enabled: true, state: 'enabled', updated_at: null },
      api_response: { mode: 'data', full_response: false, updated_at: null },
      i18n: { enabled: false, default_language: 'ru', languages: ['ru'] },
      trash_count: 0,
    });

    const route = reactive({ kind: 'folder', folderId: null, documentId: null, formId: null, dataSetId: null });
    const projects = ref([]);
    const projectMembers = ref([]);
    const availableUsers = ref([]);
    const users = ref([]);
    const projectLoading = ref(false);
    const usersLoading = ref(false);
    const selectedProjectMember = ref(null);
    const selectedUser = ref(null);
    const projectDialog = reactive({ name: '', user_id: null, role: 'editor', permissions: {} });
    const userDialog = reactive({ id: null, name: '', email: '', password: '', system_role: 'user' });
    const currentDocument = ref(null);
    const forms = ref([]);
    const currentForm = ref(null);
    const dataSets = ref([]);
    const currentDataSet = ref(null);
    const dataLoading = ref(false);
    const dataDirty = ref(false);
    const dataSaving = ref(false);
    const dataSaveState = ref('saved');
    const dataLastSavedAt = ref(null);
    const dataActiveItemUid = ref(null);
    const dataDialog = reactive({ name: '', slug: '', mode: 'single' });
    const dataUploads = reactive({});
    const dataPreviews = reactive({});
    const relationCache = reactive({});
    const relationPicker = reactive({ open:false, fieldKey:'', itemUid:null, sourceId:null, displayField:'', search:'', loading:false });
    const formSubmissions = ref([]);
    const selectedSubmission = ref(null);
    const formsLoading = ref(false);
    const formTab = ref('submissions');
    const formDirty = ref(false);
    const formSaving = ref(false);
    const formSaveState = ref('saved');
    const formLastSavedAt = ref(null);
    const loading = ref(true);
    const pageNavigating = ref(false);
    const productAbout = ref(null);
    const productAboutLoading = ref(false);
    const aboutReleaseQuery = ref('');
    const aboutDocument = ref(null);
    const aboutDocumentLoading = ref(false);
    let aboutDocumentRequestSeq = 0;
    const routeSkeletonKind = ref('explorer');
    const routeViewKey = ref(0);
    let routeTransitionStartedAt = 0;
    const saving = ref(false);
    const busy = ref(false);
    const dirty = ref(false);
    const search = ref('');
    const searchFocused = ref(false);
    const globalSearchQuery = ref('');
    const globalSearchResults = ref([]);
    const globalSearchLoading = ref(false);
    const globalSearchOpen = ref(false);
    const drawer = ref(null);
    const modal = ref(null);
    const userMenu = ref(false);
    const contextMenu = ref(null);
    const contentSelection = ref([]);
    const contentSelectionAnchor = ref(null);
    const contentClipboard = ref((() => {
      try {
        const raw = sessionStorage.getItem('feather-content-clipboard');
        const parsed = raw ? JSON.parse(raw) : null;
        if (!parsed || parsed.operation !== 'copy') return null;
        if (Array.isArray(parsed.items)) {
          const items = parsed.items.filter(item => ['folder','document'].includes(item?.type) && Number(item?.id) > 0);
          return items.length ? { ...parsed, items } : null;
        }
        if (['folder','document'].includes(parsed.type) && Number(parsed.id) > 0) {
          return { operation: parsed.operation, items: [{ type: parsed.type, id: Number(parsed.id), name: parsed.name || '', project_id: Number(parsed.project_id || 0) }] };
        }
        return null;
      } catch (_) { return null; }
    })());
    const clipboardBusy = ref(false);
    const contentSort = ref((() => {
      try { const parsed = JSON.parse(localStorage.getItem('feather-content-sort') || '{}'); return { by:['name','type','updated'].includes(parsed.by)?parsed.by:'name', direction:parsed.direction==='desc'?'desc':'asc' }; }
      catch (_) { return { by:'name', direction:'asc' }; }
    })());
    const contentSortMenu = ref(false);
    const contentProperties = ref(null);
    const trashItems = ref([]);
    const trashLoading = ref(false);
    const trashSelection = ref([]);
    const contentDrag = reactive({ active:false, overFolderId:null });
    const explorerHistory = ref([]);
    const explorerRedo = ref([]);
    const createMenu = ref(null);
    const contentLinkDialog = reactive({ type:'data', items:[], selected:[], loading:false, saving:false });
    const mediaFiles = ref([]);
    const filesLoading = ref(false);
    const fileTypeFilter = ref(['all','image','video','audio','file'].includes(localStorage.getItem('feather-file-filter')) ? localStorage.getItem('feather-file-filter') : 'all');
    const selectedFile = ref(null);
    const fileTypeFilters = [
      { key: 'all', label: 'Все', icon: 'bi-collection' },
      { key: 'image', label: 'Фото', icon: 'bi-image' },
      { key: 'video', label: 'Видео', icon: 'bi-camera-video' },
      { key: 'audio', label: 'Аудио', icon: 'bi-music-note-beamed' },
      { key: 'file', label: 'Файлы', icon: 'bi-file-earmark' },
    ];
    const viewMode = ref(localStorage.getItem('feather-view') === 'list' ? 'list' : 'grid');
    const submissionViewMode = ref(localStorage.getItem('feather-submission-view') === 'grid' ? 'grid' : 'list');
    // Multiple editors use the same simple Grid/List pattern as the file library.
    // The editor itself opens only when the user explicitly chooses “Редактировать”.
    const documentRecordViewMode = ref(localStorage.getItem('feather-document-record-view') === 'list' ? 'list' : 'grid');
    const dataRecordViewMode = ref(localStorage.getItem('feather-data-record-view') === 'list' ? 'list' : 'grid');
    const recordDialog = reactive({ open:false, kind:'', mode:'menu', uid:null, index:-1 });
    const languageCatalog = ref({});
    const languageSearch = ref('');
    const settingsLoading = ref(false);
    const settingsSaving = ref(false);
    const apiResponseSaving = ref(false);
    const folderApiPreview = ref(null);
    const folderApiPreviewLoading = ref(false);
    const folderApiSettingsSaving = ref(false);
    const folderApiSettings = reactive({ cache_ttl:60, cache_enabled:true, cache_backend:'File cache' });
    const storedTheme = (() => {
      try {
        const value = localStorage.getItem('matercms-theme') || localStorage.getItem('mater-theme') || localStorage.getItem('feather-theme');
        return ['system','light','dark'].includes(value) ? value : 'system';
      } catch { return 'system'; }
    })();
    const themeMode = ref(storedTheme);
    const settingsSaveQueued = ref(false);
    const databaseInfo = ref(config.database || {});
    const databaseAvailability = ref({});
    const databaseSwitching = ref(false);
    const databaseDialog = reactive({
      driver: 'sqlite',
      input_mode: 'url',
      url: '',
      host: '127.0.0.1',
      port: '',
      database: '',
      username: '',
      password: '',
      sslmode: 'prefer',
    });
    const editorLanguage = ref('ru');
    const dialog = reactive({ name: '', type: '', target: null, mode: 'single' });
    const activeItemUid = ref(null);
    const uploads = reactive({});
    const previews = reactive({});
    const toasts = ref([]);
    const saveState = ref('saved'); // saved | pending | saving | error
    const lastSavedAt = ref(null);
    const versions = ref([]);
    const versionsLoading = ref(false);
    const restoring = ref(false);
    const apiLanguage = ref('javascript');
    const apiSecret = ref('');
    const confirmDialog = reactive({
      open: false,
      title: '',
      message: '',
      confirmLabel: 'Подтвердить',
      cancelLabel: 'Отмена',
      tone: 'danger',
      icon: 'bi-exclamation-triangle',
      resolve: null,
    });
    let toastId = 0;
    let autosaveTimer = null;
    let formAutosaveTimer = null;
    let dataAutosaveTimer = null;
    let globalSearchTimer = null;
    let globalSearchSerial = 0;
    let changeSerial = 0;
    let formChangeSerial = 0;
    let dataChangeSerial = 0;
    let activeSavePromise = null;
    let activeFormSavePromise = null;
    let activeDataSavePromise = null;
    // A session changes every time another editor model is loaded. Async save responses
    // from an older session must never mutate the newly opened editor.
    let documentSession = 0;
    let formSession = 0;
    let dataSession = 0;
    const autosaveDelay = Math.max(450, Number(config.autosaveDelay || 1100));

    const typeNames = {
      text: 'Короткий текст',
      textarea: 'Большой текст',
      number: 'Число',
      boolean: 'Переключатель',
      date: 'Дата',
      datetime: 'Дата и время',
      link: 'Ссылка',
      email: 'Email',
      phone: 'Телефон',
      color: 'Цвет',
      image: 'Изображение',
      video: 'Видео',
      audio: 'Аудио',
      file: 'Файл',
    };


    const formTypeNames = {
      text: 'Короткий текст',
      textarea: 'Большой текст',
      email: 'Email',
      phone: 'Телефон',
      number: 'Число',
      select: 'Выбор из списка',
      checkbox: 'Согласие / checkbox',
      date: 'Дата',
      file: 'Файл',
    };

    const dataTypeNames = {
      text: 'Текст',
      textarea: 'Большой текст',
      number: 'Число',
      boolean: 'Переключатель',
      date: 'Дата',
      datetime: 'Дата и время',
      select: 'Выбор из списка',
      image: 'Изображение',
      video: 'Видео',
      audio: 'Аудио',
      file: 'Файл',
      relation: 'Связь',
    };

    const initials = computed(() => {
      const name = state.user?.name || 'A';
      return name.trim().split(/\s+/).slice(0, 2).map(part => part[0]?.toUpperCase() || '').join('') || 'A';
    });

    const can = permission => !!state.permissions?.[permission];
    const isSystemOwner = computed(() => state.user?.system_role === 'owner');
    const currentProject = computed(() => state.current_project || null);
    const currentProjectRoleLabel = computed(() => ({owner:'Владелец',admin:'Администратор',editor:'Редактор',viewer:'Наблюдатель'}[state.current_project?.role] || 'Участник'));
    const apiProjectEnabled = computed(() => state.api_status?.enabled !== false);
    const apiFullResponse = computed(() => state.api_response?.full_response === true);
    const apiResponseModeLabel = computed(() => apiFullResponse.value ? 'Полный ответ' : 'Только данные');
    const contentKey = (type, id) => `${type}:${Number(id)}`;
    const contentClipboardCount = computed(() => contentClipboard.value?.items?.length || 0);
    const contentClipboardLabel = computed(() => {
      const items = contentClipboard.value?.items || [];
      if (!items.length) return '';
      if (items.length === 1) return items[0].name || '';
      return `${items.length} объектов`;
    });
    const contentSelectionCount = computed(() => contentSelection.value.length);
    const isContentSelected = (type, item) => !!item && contentSelection.value.some(selection => selection.type === type && Number(selection.id) === Number(item.id));
    const selectedContentItems = computed(() => contentSelection.value.map(selection => {
      const source = selection.type === 'folder'
        ? state.folders
        : (selection.type === 'document' ? state.documents : (state.content_links || []));
      const item = source.find(entry => Number(entry.id) === Number(selection.id));
      return item ? { ...item, type: selection.type } : null;
    }).filter(Boolean));
    const replaceContentSelection = items => {
      contentSelection.value = (Array.isArray(items) ? items : []).map(item => ({ type:item.type, id:Number(item.id), name:String(item.name || '') }));
    };
    const selectContentItem = (type, item, event = null) => {
      if (!item || !['folder','document','resource-link'].includes(type)) return;
      const target = { type, id:Number(item.id), name:String(item.name || '') };
      const key = contentKey(type,item.id);
      const items = typeof contentOrderedItems !== 'undefined' ? contentOrderedItems.value : [];
      if (event?.shiftKey && contentSelectionAnchor.value && items.length) {
        const a = items.findIndex(entry => contentKey(entry.type,entry.id) === contentSelectionAnchor.value);
        const b = items.findIndex(entry => contentKey(entry.type,entry.id) === key);
        if (a >= 0 && b >= 0) {
          const range = items.slice(Math.min(a,b),Math.max(a,b)+1).map(entry => ({type:entry.type,id:Number(entry.id),name:String(entry.name||'')}));
          if (event.ctrlKey || event.metaKey) {
            const map = new Map(contentSelection.value.map(entry => [contentKey(entry.type,entry.id),entry]));
            range.forEach(entry => map.set(contentKey(entry.type,entry.id),entry));
            replaceContentSelection([...map.values()]);
          } else replaceContentSelection(range);
          return;
        }
      }
      if (event?.ctrlKey || event?.metaKey) {
        const existing = contentSelection.value.findIndex(entry => entry.type===type && Number(entry.id)===Number(item.id));
        if (existing >= 0) contentSelection.value = contentSelection.value.filter((_,index)=>index!==existing);
        else contentSelection.value = [...contentSelection.value,target];
        contentSelectionAnchor.value = key;
        return;
      }
      replaceContentSelection([target]);
      contentSelectionAnchor.value = key;
    };
    const activateContentItem = (event, type, item) => {
      selectContentItem(type, item, event);
      const keyboardActivation = Number(event?.detail || 0) === 0;
      const coarsePointer = window.matchMedia?.('(pointer: coarse)')?.matches === true;
      if (keyboardActivation || coarsePointer) {
        if (type === 'folder') return openFolder(item.id);
        if (type === 'document') return openDocument(item.id);
        return openLinkedResource(item);
      }
    };
    const databaseCanSubmit = computed(() => {
      const meta = databaseAvailability.value?.[databaseDialog.driver];
      if (!meta?.available) return false;
      if (databaseDialog.driver === 'sqlite') return databaseInfo.value?.driver !== 'sqlite';
      if (databaseDialog.input_mode === 'url') return databaseDialog.url.trim().length > 0;
      return databaseDialog.host.trim().length > 0
        && databaseDialog.database.trim().length > 0
        && databaseDialog.username.trim().length > 0;
    });

    const foldersMap = computed(() => new Map(state.folders.map(folder => [folder.id, folder])));
    const currentFolder = computed(() => route.kind === 'folder' && route.folderId ? foldersMap.value.get(route.folderId) || null : null);
    const folderEndpoint = computed(() => currentFolder.value?.endpoint || '');
    const currentApiResourceEnabled = computed(() => {
      if (!apiProjectEnabled.value) return false;
      if (currentDocument.value) return currentDocument.value.api_enabled !== false;
      if (currentFolder.value) return currentFolder.value.api_enabled !== false;
      return true;
    });
    const documentLanguages = computed(() => {
      const doc = currentDocument.value;
      const i18n = doc?.i18n || state.i18n;
      const codes = i18n?.enabled ? (i18n.languages || []) : [i18n?.default_language || 'ru'];
      return codes.map(code => ({ code, ...(languageCatalog.value[code] || { name: code.toUpperCase(), native: code.toUpperCase() }) }));
    });
    const isDefaultEditorLanguage = computed(() => {
      const defaultLanguage = currentDocument.value?.i18n?.default_language || state.i18n?.default_language || 'ru';
      return editorLanguage.value === defaultLanguage;
    });
    const activeDocumentData = computed(() => {
      const doc = currentDocument.value;
      if (!doc) return null;
      const defaultLanguage = doc.i18n?.default_language || state.i18n?.default_language || 'ru';
      if (!doc.i18n?.enabled || editorLanguage.value === defaultLanguage) return doc.data;
      return doc.translations?.[editorLanguage.value] ?? null;
    });
    const activeItem = computed(() => {
      const doc = currentDocument.value;
      const dataset = activeDocumentData.value;
      if (!doc || doc.mode !== 'multiple' || !Array.isArray(dataset)) return null;
      return dataset.find(item => item._uid === activeItemUid.value) || null;
    });
    const activeData = computed(() => {
      const doc = currentDocument.value;
      const dataset = activeDocumentData.value;
      if (!doc || dataset === null) return null;
      return doc.mode === 'multiple' ? activeItem.value : dataset;
    });
    const currentVersionCount = computed(() => {
      const doc = currentDocument.value;
      if (!doc) return 0;
      const defaultLanguage = doc.i18n?.default_language || state.i18n?.default_language || 'ru';
      const key = editorLanguage.value === defaultLanguage ? '' : editorLanguage.value;
      return Number(doc.version_counts?.[key] ?? (key === '' ? doc.version_count : 0) ?? 0);
    });

    const folderTrail = folderId => {
      const trail = [];
      const guard = new Set();
      let id = folderId;
      while (id && !guard.has(id)) {
        guard.add(id);
        const folder = foldersMap.value.get(id);
        if (!folder) break;
        trail.unshift(folder);
        id = folder.parent_id;
      }
      return trail;
    };

    const breadcrumbs = computed(() => folderTrail(route.folderId));
    const documentBreadcrumbs = computed(() => folderTrail(currentDocument.value?.folder_id || null));

    const currentFolders = computed(() => state.folders.filter(folder => folder.parent_id === route.folderId));
    const currentDocuments = computed(() => state.documents.filter(doc => doc.folder_id === route.folderId));
    const currentContentLinks = computed(() => (state.content_links || []).filter(link => link.folder_id === route.folderId));
    const normalizedSearch = computed(() => search.value.trim().toLocaleLowerCase('ru'));
    const globalSearchGroups = computed(() => {
      const order = ['Папки','Разделы','Данные','Формы','Файлы','Настройки'];
      const map = new Map();
      for (const item of globalSearchResults.value) {
        const name = item.category || 'Другое';
        if (!map.has(name)) map.set(name, []);
        map.get(name).push(item);
      }
      return [...map.entries()]
        .sort((a,b) => {
          const ai = order.indexOf(a[0]); const bi = order.indexOf(b[0]);
          return (ai < 0 ? 99 : ai) - (bi < 0 ? 99 : bi);
        })
        .map(([name,items]) => ({ name, items }));
    });
    const compareContentItems = (a,b) => {
      const by = contentSort.value.by;
      const direction = contentSort.value.direction === 'desc' ? -1 : 1;
      let result = 0;
      if (by === 'updated') result = String(a.updated_at || a.created_at || '').localeCompare(String(b.updated_at || b.created_at || ''));
      else if (by === 'type') result = String(a.type || '').localeCompare(String(b.type || ''), 'ru') || String(a.name || '').localeCompare(String(b.name || ''), 'ru', {sensitivity:'base',numeric:true});
      else result = String(a.name || '').localeCompare(String(b.name || ''), 'ru', {sensitivity:'base',numeric:true});
      return result * direction;
    };
    const filteredFolders = computed(() => currentFolders.value
      .filter(item => !normalizedSearch.value || item.name.toLocaleLowerCase('ru').includes(normalizedSearch.value))
      .map(item => ({...item,type:'folder'})).sort(compareContentItems));
    const filteredDocuments = computed(() => currentDocuments.value
      .filter(item => !normalizedSearch.value || item.name.toLocaleLowerCase('ru').includes(normalizedSearch.value))
      .map(item => ({...item,type:'document'})).sort(compareContentItems));
    const filteredContentLinks = computed(() => currentContentLinks.value
      .filter(item => !normalizedSearch.value || `${item.name} ${item.slug || ''} ${item.resource_type || ''}`.toLocaleLowerCase('ru').includes(normalizedSearch.value))
      .map(item => ({...item,type:'resource-link'})).sort(compareContentItems));
    const contentVisibleCount = computed(() => filteredFolders.value.length + filteredDocuments.value.length + filteredContentLinks.value.length);
    const contentOrderedItems = computed(() =>
      [...filteredFolders.value,...filteredDocuments.value,...filteredContentLinks.value].sort(compareContentItems)
    );
    const filteredForms = computed(() => forms.value.filter(item => !normalizedSearch.value || item.name.toLocaleLowerCase('ru').includes(normalizedSearch.value)));
    const filteredDataSets = computed(() => dataSets.value.filter(item => !normalizedSearch.value || `${item.name} ${item.slug}`.toLocaleLowerCase('ru').includes(normalizedSearch.value)));
    const relationSourceDataSets = computed(() => dataSets.value.filter(item => item.mode === 'multiple' && item.id !== currentDataSet.value?.id));
    const relationPickerRecords = computed(() => {
      const key = `${Number(relationPicker.sourceId || 0)}:${relationPicker.displayField || ''}`;
      const records = Array.isArray(relationCache[key]?.records) ? relationCache[key].records : [];
      const query = relationPicker.search.trim().toLocaleLowerCase('ru');
      return query ? records.filter(item => String(item.label || '').toLocaleLowerCase('ru').includes(query) || String(item.id).includes(query)) : records;
    });
    const filteredFormSubmissions = computed(() => formSubmissions.value.filter(item => {
      if (!normalizedSearch.value) return true;
      const haystack = [item.preview, ...Object.values(item.data || {}).flat()].filter(value => typeof value === 'string').join(' ').toLocaleLowerCase('ru');
      return haystack.includes(normalizedSearch.value);
    }));
    const filteredMediaFiles = computed(() => mediaFiles.value.filter(item => {
      if (fileTypeFilter.value !== 'all' && item.kind !== fileTypeFilter.value) return false;
      if (!normalizedSearch.value) return true;
      return [item.name, item.document_name, item.data_set_name, item.form_name, item.mime, item.extension].filter(Boolean).some(value => String(value).toLocaleLowerCase('ru').includes(normalizedSearch.value));
    }));
    const fileTypeCount = kind => kind === 'all' ? mediaFiles.value.length : mediaFiles.value.filter(item => item.kind === kind).length;
    const setFileTypeFilter = kind => {
      if (!fileTypeFilters.some(item => item.key === kind)) kind = 'all';
      fileTypeFilter.value = kind;
      localStorage.setItem('feather-file-filter', kind);
      contextMenu.value = null;
    };

    const selectedLanguages = computed(() => (state.i18n?.languages || []).map(code => ({ code, ...(languageCatalog.value[code] || { name: code.toUpperCase(), native: code.toUpperCase() }) })));
    const filteredLanguages = computed(() => {
      const query = languageSearch.value.trim().toLocaleLowerCase('ru');
      return Object.entries(languageCatalog.value).map(([code, item]) => ({ code, ...item })).filter(item => {
        if (!query) return true;
        return `${item.code} ${item.name || ''} ${item.native || ''}`.toLocaleLowerCase('ru').includes(query);
      });
    });

    const dataActiveItem = computed(() => {
      const set = currentDataSet.value;
      if (!set || set.mode !== 'multiple' || !Array.isArray(set.data)) return null;
      return set.data.find(item => item._uid === dataActiveItemUid.value) || null;
    });
    const dataActiveData = computed(() => {
      const set = currentDataSet.value;
      if (!set) return null;
      return set.mode === 'multiple' ? dataActiveItem.value : set.data;
    });
    const dataAutosaveText = computed(() => {
      if (dataSaveState.value === 'saving') return 'Сохраняем…';
      if (dataSaveState.value === 'pending') return 'Сохраним через секунду';
      if (dataSaveState.value === 'error') return 'Не удалось сохранить';
      return dataLastSavedAt.value ? `Сохранено ${formatTime(dataLastSavedAt.value)}` : 'Все изменения сохранены';
    });
    const dataEndpoint = computed(() => currentDataSet.value?.endpoint || '');
    const dataApiExamples = computed(() => {
      const endpoint = dataEndpoint.value;
      const isPrivate = state.api_access?.mode === 'private';
      if (isPrivate) return {
        javascript: `// Server-side JavaScript (Node.js)\nconst token = process.env.MATERCMS_API_TOKEN;\nconst response = await fetch('${endpoint}', { headers: { Authorization: \`Bearer \${token}\` } });\nconst data = await response.json();\nconsole.log(data);`,
        php: `$token = getenv('MATERCMS_API_TOKEN');\n$ch = curl_init('${endpoint}');\ncurl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token]]);\n$data = json_decode(curl_exec($ch), true);\ncurl_close($ch);`,
        python: `import os, requests\ntoken = os.environ['MATERCMS_API_TOKEN']\nr = requests.get('${endpoint}', headers={'Authorization': f'Bearer {token}'}, timeout=15)\nr.raise_for_status()\nprint(r.json())`,
        curl: `curl '${endpoint}' --header "Authorization: Bearer $MATERCMS_API_TOKEN"`,
      };
      return {
        javascript: `const response = await fetch('${endpoint}');\nconst data = await response.json();\nconsole.log(data);`,
        php: `$data = json_decode(file_get_contents('${endpoint}'), true);\nprint_r($data);`,
        python: `import requests\nr = requests.get('${endpoint}', timeout=15)\nr.raise_for_status()\nprint(r.json())`,
        curl: `curl '${endpoint}'`,
      };
    });
    const dataApiExampleCode = computed(() => dataApiExamples.value[apiLanguage.value] || dataApiExamples.value.javascript);

    const autosaveText = computed(() => {
      if (saveState.value === 'saving') return 'Сохраняем…';
      if (saveState.value === 'pending') return 'Сохраним через секунду';
      if (saveState.value === 'error') return 'Не удалось сохранить';
      return lastSavedAt.value ? `Сохранено ${formatTime(lastSavedAt.value)}` : 'Все изменения сохранены';
    });

    const absoluteApiUrl = computed(() => {
      const path = currentDocument.value?.api_path || currentFolder.value?.endpoint || state.public_api || `${state.base || ''}/api/`;
      try {
        const resolved = new URL(path, window.location.origin);
        const doc = currentDocument.value;
        const defaultLanguage = doc?.i18n?.default_language || state.i18n?.default_language || 'ru';
        if (doc?.i18n?.enabled && editorLanguage.value !== defaultLanguage) resolved.searchParams.set('lang', editorLanguage.value);
        return resolved.href;
      } catch (_) { return `${window.location.origin}${String(path || '').startsWith('/') ? '' : '/'}${path || ''}`; }
    });

    const absoluteApiRoot = computed(() => {
      if (state.public_api_absolute) return state.public_api_absolute;
      const path = state.public_api || `${state.base || ''}/api/`;
      try { return new URL(path, window.location.origin).href; }
      catch (_) { return `${window.location.origin}${String(path || '').startsWith('/') ? '' : '/'}${path || ''}`; }
    });

    const apiExamples = computed(() => {
      const endpoint = absoluteApiUrl.value;
      const isPrivate = state.api_access?.mode === 'private';
      if (isPrivate) {
        return {
          javascript: `// Server-side JavaScript (Node.js). Не публикуйте токен в браузере.\nconst token = process.env.MATERCMS_API_TOKEN;\n\nconst response = await fetch('${endpoint}', {\n  headers: {\n    'Accept': 'application/json',\n    'Authorization': \`Bearer \${token}\`\n  }\n});\n\nif (!response.ok) throw new Error('HTTP ' + response.status);\nconst data = await response.json();\nconsole.log(data);`,
          php: `$url = '${endpoint}';\n$token = getenv('MATERCMS_API_TOKEN');\n\n$ch = curl_init($url);\ncurl_setopt_array($ch, [\n  CURLOPT_RETURNTRANSFER => true,\n  CURLOPT_HTTPHEADER => [\n    'Accept: application/json',\n    'Authorization: Bearer ' . $token,\n  ],\n]);\n$response = curl_exec($ch);\nif ($response === false) throw new RuntimeException(curl_error($ch));\ncurl_close($ch);\n\n$data = json_decode($response, true);\nprint_r($data);`,
          python: `import os\nimport requests\n\ntoken = os.environ['MATERCMS_API_TOKEN']\nresponse = requests.get(\n    '${endpoint}',\n    headers={'Authorization': f'Bearer {token}'},\n    timeout=15,\n)\nresponse.raise_for_status()\nprint(response.json())`,
          curl: `curl --request GET \\\n  --url '${endpoint}' \\\n  --header 'Accept: application/json' \\\n  --header "Authorization: Bearer $MATERCMS_API_TOKEN"`,
        };
      }
      return {
        javascript: `fetch('${endpoint}')\n  .then(response => {\n    if (!response.ok) throw new Error('HTTP ' + response.status);\n    return response.json();\n  })\n  .then(data => console.log(data));`,
        php: `$url = '${endpoint}';\n$json = file_get_contents($url);\n$data = json_decode($json, true);\n\nprint_r($data);`,
        python: `import requests\n\nresponse = requests.get('${endpoint}', timeout=15)\nresponse.raise_for_status()\ndata = response.json()\n\nprint(data)`,
        curl: `curl --request GET \\\n  --url '${endpoint}' \\\n  --header 'Accept: application/json'`,
      };
    });

    const apiExampleCode = computed(() => apiExamples.value[apiLanguage.value] || apiExamples.value.javascript);

    const formAutosaveText = computed(() => {
      if (formSaveState.value === 'saving') return 'Сохраняем…';
      if (formSaveState.value === 'pending') return 'Сохраним через секунду';
      if (formSaveState.value === 'error') return 'Не удалось сохранить';
      return formLastSavedAt.value ? `Сохранено ${formatTime(formLastSavedAt.value)}` : 'Все изменения сохранены';
    });

    const formEndpoint = computed(() => currentForm.value?.endpoint || '');
    const formIntegrationExamples = computed(() => {
      const endpoint = formEndpoint.value;
      const fields = Array.isArray(currentForm.value?.schema) ? currentForm.value.schema : [];
      const body = {};
      fields.filter(field => field.type !== 'file').forEach(field => {
        body[field.key] = field.type === 'checkbox' ? true : field.type === 'number' ? 1 : field.type === 'email' ? 'mail@example.com' : 'Значение';
      });
      const jsonBody = JSON.stringify(body, null, 2);
      const htmlFields = fields.map(field => {
        const req = field.required ? ' required' : '';
        if (field.type === 'textarea') return `  <textarea name="${field.key}" placeholder="${field.placeholder || field.label}"${req}></textarea>`;
        if (field.type === 'select') return `  <select name="${field.key}"${req}>\n${(field.options || []).map(option => `    <option value="${option}">${option}</option>`).join('\n')}\n  </select>`;
        if (field.type === 'checkbox') return `  <label><input type="checkbox" name="${field.key}" value="1"${req}> ${field.label}</label>`;
        if (field.type === 'file') return `  <input type="file" name="${field.key}${field.multiple ? '[]' : ''}"${field.multiple ? ' multiple' : ''}${req}>`;
        const type = ['email','number','date'].includes(field.type) ? field.type : field.type === 'phone' ? 'tel' : 'text';
        return `  <input type="${type}" name="${field.key}" placeholder="${field.placeholder || field.label}"${req}>`;
      }).join('\n');
      const isPrivate = state.api_access?.mode === 'private';
      if (isPrivate) {
        return {
          javascript: `// Server-side JavaScript (Node.js). MATERCMS_API_TOKEN храните в .env.\nconst token = process.env.MATERCMS_API_TOKEN;\nconst response = await fetch('${endpoint}', {\n  method: 'POST',\n  headers: {\n    'Content-Type': 'application/json',\n    'Authorization': \`Bearer \${token}\`\n  },\n  body: JSON.stringify(${jsonBody})\n});\n\nif (!response.ok) throw new Error('HTTP ' + response.status);\nconsole.log(await response.json());`,
          html: `<form action="/send-form.php" method="post" enctype="multipart/form-data">\n${htmlFields}\n  <input type="text" name="_website" tabindex="-1" autocomplete="off" style="display:none">\n  <button type="submit">Отправить</button>\n</form>\n\n<!-- Приватный токен нельзя помещать в HTML. /send-form.php должен отправить данные в MaterCMS сервер-сервер с Authorization: Bearer ... -->`,
          php: `$url = '${endpoint}';\n$token = getenv('MATERCMS_API_TOKEN');\n$data = json_decode('${JSON.stringify(body).replace(/'/g, "\\'")}', true);\n\n$ch = curl_init($url);\ncurl_setopt_array($ch, [\n  CURLOPT_POST => true,\n  CURLOPT_HTTPHEADER => [\n    'Content-Type: application/json',\n    'Authorization: Bearer ' . $token,\n  ],\n  CURLOPT_POSTFIELDS => json_encode($data, JSON_UNESCAPED_UNICODE),\n  CURLOPT_RETURNTRANSFER => true,\n]);\n$response = curl_exec($ch);\nif ($response === false) throw new RuntimeException(curl_error($ch));\ncurl_close($ch);\nprint_r(json_decode($response, true));`,
          python: `import os\nimport requests\n\ntoken = os.environ['MATERCMS_API_TOKEN']\ndata = ${JSON.stringify(body, null, 2).replace(/true/g, 'True').replace(/false/g, 'False').replace(/null/g, 'None')}\nresponse = requests.post(\n    '${endpoint}',\n    json=data,\n    headers={'Authorization': f'Bearer {token}'},\n    timeout=15,\n)\nresponse.raise_for_status()\nprint(response.json())`,
          curl: `curl --request POST \\\n  --url '${endpoint}' \\\n  --header 'Content-Type: application/json' \\\n  --header "Authorization: Bearer $MATERCMS_API_TOKEN" \\\n  --data '${JSON.stringify(body)}'`,
        };
      }
      return {
        javascript: `const response = await fetch('${endpoint}', {\n  method: 'POST',\n  headers: { 'Content-Type': 'application/json' },\n  body: JSON.stringify(${jsonBody})\n});\n\nconst result = await response.json();\nconsole.log(result);`,
        html: `<form action="${endpoint}" method="post" enctype="multipart/form-data">\n${htmlFields}\n  <input type="text" name="_website" tabindex="-1" autocomplete="off" style="display:none">\n  <button type="submit">Отправить</button>\n</form>`,
        php: `$url = '${endpoint}';\n$data = json_decode('${JSON.stringify(body).replace(/'/g, "\\'")}', true);\n\n$ch = curl_init($url);\ncurl_setopt_array($ch, [\n  CURLOPT_POST => true,\n  CURLOPT_HTTPHEADER => ['Content-Type: application/json'],\n  CURLOPT_POSTFIELDS => json_encode($data, JSON_UNESCAPED_UNICODE),\n  CURLOPT_RETURNTRANSFER => true,\n]);\n$result = json_decode(curl_exec($ch), true);\ncurl_close($ch);`,
        python: `import json\nimport requests\n\ndata = json.loads(r'''${JSON.stringify(body)}''')\nresponse = requests.post('${endpoint}', json=data, timeout=15)\nresponse.raise_for_status()\nprint(response.json())`,
        curl: `curl --request POST \\\n  --url '${endpoint}' \\\n  --header 'Content-Type: application/json' \\\n  --data '${JSON.stringify(body)}'`,
      };
    });
    const formExampleCode = computed(() => formIntegrationExamples.value[apiLanguage.value] || formIntegrationExamples.value.javascript);

    const aiAuthInstruction = computed(() => state.api_access?.mode === 'private'
      ? 'Проект приватный. Никогда не помещай секрет в HTML, browser JavaScript или client bundle. Используй переменную окружения MATERCMS_API_TOKEN только на backend/serverless и передавай Authorization: Bearer <token>.'
      : 'Проект публичный. Для чтения Bearer-токен не требуется.');

    const hasPromptValue = value => {
      if (value === null || value === undefined || value === '') return false;
      if (Array.isArray(value)) return value.length > 0;
      return true;
    };

    const promptFallbackValue = field => {
      const type = String(field?.type || 'text');
      if (type === 'number') return 0;
      if (type === 'boolean' || type === 'checkbox') return false;
      if (type === 'date') return '2026-09-08';
      if (type === 'datetime') return '2026-09-08T12:00:00';
      if (type === 'email') return 'hello@example.com';
      if (type === 'phone') return '+7 700 000 00 00';
      if (type === 'link') return 'https://example.com';
      if (type === 'color') return '#4b72ff';
      if (type === 'image') return field?.multiple ? ['https://example.com/image.webp'] : 'https://example.com/image.webp';
      if (type === 'video') return field?.multiple ? ['https://example.com/video.mp4'] : 'https://example.com/video.mp4';
      if (type === 'audio') return field?.multiple ? ['https://example.com/audio.mp3'] : 'https://example.com/audio.mp3';
      if (type === 'file') return field?.multiple ? ['https://example.com/file.pdf'] : 'https://example.com/file.pdf';
      if (type === 'select') return Array.isArray(field?.options) && field.options.length ? field.options[0] : 'Вариант';
      if (type === 'textarea') return 'Пример большого текста';
      return field?.label ? `Пример: ${field.label}` : 'Пример значения';
    };

    const cleanPromptValue = value => {
      if (Array.isArray(value)) return value.slice(0, 3).map(cleanPromptValue);
      if (!value || typeof value !== 'object') {
        if (typeof value === 'string' && value.length > 500) return value.slice(0, 497) + '…';
        return value;
      }
      const result = {};
      for (const [key,item] of Object.entries(value)) {
        if (key === '_uid') continue;
        if (key === '_id') { result.id = Number(item) || item; continue; }
        result[key] = cleanPromptValue(item);
      }
      return result;
    };

    const documentPromptExample = computed(() => {
      const doc = currentDocument.value;
      if (!doc) {
        return {
          project: state.current_project?.name || 'MaterCMS project',
          api: absoluteApiRoot.value,
          note: 'Откройте конкретный раздел, чтобы MaterCMS добавил пример его JSON-ответа.',
        };
      }
      const defaultLanguage = doc.i18n?.default_language || state.i18n?.default_language || 'ru';
      if (!dirty.value && (!doc.i18n?.enabled || editorLanguage.value === defaultLanguage) && doc.api_sample) {
        const sample = cleanPromptValue(doc.api_sample);
        const hasSample = Array.isArray(sample) ? sample.length > 0 : sample && Object.keys(sample).length > 0;
        if (hasSample) return sample;
      }
      const schema = Array.isArray(doc.schema) ? doc.schema : [];
      const raw = doc.mode === 'multiple'
        ? (Array.isArray(activeDocumentData.value) ? (activeDocumentData.value[0] || {}) : {})
        : (activeDocumentData.value && typeof activeDocumentData.value === 'object' ? activeDocumentData.value : {});
      const item = {};
      for (const field of schema) {
        const value = raw?.[field.key];
        item[field.key] = cleanPromptValue(hasPromptValue(value) ? value : promptFallbackValue(field));
      }
      if (!schema.length) Object.assign(item, cleanPromptValue(raw));
      return doc.mode === 'multiple' ? [item] : item;
    });

    const dataPromptExample = computed(() => {
      const set = currentDataSet.value;
      if (!set) return {};
      if (!dataDirty.value && set.api_sample) {
        const sample = cleanPromptValue(set.api_sample);
        const hasSample = Array.isArray(sample) ? sample.length > 0 : sample && Object.keys(sample).length > 0;
        if (hasSample) return sample;
      }
      const schema = Array.isArray(set.schema) ? set.schema : [];
      const raw = set.mode === 'multiple'
        ? (Array.isArray(set.data) ? (set.data[0] || {}) : {})
        : (set.data && typeof set.data === 'object' ? set.data : {});
      const item = {};
      if (set.mode === 'multiple' && Number(raw?._id || 0) > 0) item.id = Number(raw._id);
      for (const field of schema) {
        const value = raw?.[field.key];
        if (field.type === 'relation') {
          const ids = field.relation_multiple
            ? (Array.isArray(value) ? value : [])
            : (value ? [value] : []);
          const relationExample = ids.length
            ? ids.slice(0, 2).map(id => ({ id: Number(id), name: relationLabel(field, id) }))
            : [{ id: 1, name: relationSource(field)?.name ? `Пример из «${relationSource(field).name}»` : 'Связанная запись' }];
          item[field.key] = field.relation_multiple ? relationExample : relationExample[0];
          continue;
        }
        item[field.key] = cleanPromptValue(hasPromptValue(value) ? value : promptFallbackValue(field));
      }
      return set.mode === 'multiple' ? [item] : item;
    });

    const formPromptRequestExample = computed(() => {
      const fields = Array.isArray(currentForm.value?.schema) ? currentForm.value.schema : [];
      const body = {};
      for (const field of fields) {
        if (field.type === 'file') {
          body[field.key] = field.multiple ? ['<файл 1>', '<файл 2>'] : '<файл>';
        } else {
          body[field.key] = promptFallbackValue(field);
        }
      }
      return body;
    });

    const apiResponseJson = value => JSON.stringify(value, null, 2) || '{}';

    const apiResponseMetaBase = (name, mode, endpoint, updatedAt = null) => {
      let path = endpoint;
      try { path = new URL(endpoint, window.location.origin).pathname; } catch (_) {}
      return {
        project: {
          name: currentProject.value?.name || 'Проект',
          slug: currentProject.value?.slug || state.project_api_namespace || 'main',
        },
        name,
        mode: mode === 'multiple' ? 'multiple' : 'single',
        returns: mode === 'multiple' ? 'array' : 'object',
        updated_at: updatedAt || null,
        path,
        url: endpoint,
      };
    };

    const documentResponsePreview = computed(() => {
      const doc = currentDocument.value;
      if (!doc) return {};
      const data = documentPromptExample.value;
      if (!apiFullResponse.value) return data;
      const meta = apiResponseMetaBase(doc.name, doc.mode, absoluteApiUrl.value, doc.updated_at);
      if (doc.i18n?.enabled) {
        const defaultLanguage = doc.i18n.default_language || state.i18n?.default_language || 'ru';
        meta.i18n = doc.i18n;
        meta.language = {
          requested: editorLanguage.value,
          resolved: editorLanguage.value,
          fallback: false,
          default: defaultLanguage,
        };
      }
      return { data, meta };
    });

    const dataResponsePreview = computed(() => {
      const set = currentDataSet.value;
      if (!set) return {};
      const data = dataPromptExample.value;
      if (!apiFullResponse.value) return data;
      return {
        data,
        meta: {
          ...apiResponseMetaBase(set.name, set.mode, dataEndpoint.value, set.updated_at),
          slug: set.slug,
        },
      };
    });

    const formResponsePreview = computed(() => {
      const form = currentForm.value;
      const compact = {
        ok: true,
        message: form?.success_message || 'Спасибо! Данные отправлены.',
      };
      if (!apiFullResponse.value) return compact;
      return {
        ...compact,
        submission_id: 123,
        project: currentProject.value?.slug || state.project_api_namespace || 'main',
        form: form?.slug || 'form',
      };
    });

    const projectResponsePreview = computed(() => {
      const project = currentProject.value || {};
      const root = absoluteApiRoot.value;
      const makeUrl = path => {
        try { return new URL(path, window.location.origin).href; }
        catch (_) { return path; }
      };
      return {
        project: {
          id: Number(project.id || 0) || 1,
          name: project.name || 'Мой проект',
          slug: project.slug || state.project_api_namespace || 'main',
          url: root,
          access: state.api_access?.mode || 'public',
          response_mode: state.api_response?.mode || 'data',
        },
        folders: (state.folders || []).filter(item => item.api_enabled !== false).slice(0, 6).map(item => ({ name:item.name, type:'folder-tree', method:'GET', url:item.endpoint || '' })),
        endpoints: (state.documents || []).filter(doc => doc.api_enabled !== false).slice(0, 6).map(doc => {
          const segments = [];
          let parentId = doc.folder_id;
          const seen = new Set();
          while (parentId && !seen.has(Number(parentId))) {
            seen.add(Number(parentId));
            const folder = (state.folders || []).find(item => Number(item.id) === Number(parentId));
            if (!folder) break;
            segments.unshift(folder.slug);
            parentId = folder.parent_id;
          }
          segments.push(doc.slug || '');
          const relative = `${state.base || ''}/api/${project.slug || state.project_api_namespace || 'main'}/${segments.filter(Boolean).join('/')}`;
          return { name: doc.name, mode: doc.mode, returns: doc.mode === 'multiple' ? 'array' : 'object', path: relative, url: makeUrl(relative) };
        }),
        forms: (forms.value || []).filter(item => item.api_enabled !== false).slice(0, 6).map(item => ({ name:item.name, method:'POST', url:item.endpoint || '' })),
        data: (dataSets.value || []).filter(item => item.api_enabled !== false).slice(0, 6).map(item => ({ name:item.name, method:'GET', mode:item.mode, returns:item.mode === 'multiple' ? 'array' : 'object', url:item.endpoint || '' })),
        i18n: state.i18n,
      };
    });

    const stripFolderTreeContent = node => ({
      ...node,
      folders: Array.isArray(node?.folders) ? node.folders.map(stripFolderTreeContent) : [],
      sections: Array.isArray(node?.sections) ? node.sections.map(section => {
        const { data, ...info } = section || {};
        return info;
      }) : [],
      data_sets: Array.isArray(node?.data_sets) ? node.data_sets.map(item => {
        const { data, ...info } = item || {};
        return info;
      }) : [],
      forms: Array.isArray(node?.forms) ? node.forms.map(item => {
        const { schema, success_message, ...info } = item || {};
        return info;
      }) : [],
    });

    const folderResponsePreview = computed(() => {
      const fullTree = folderApiPreview.value || (currentFolder.value ? {
        type:'folder',
        name:currentFolder.value.name,
        slug:currentFolder.value.slug,
        path:currentFolder.value.endpoint || '',
        url:currentFolder.value.endpoint || '',
        folders:[],
        sections:[],
        data_sets:[],
        forms:[],
      } : {});
      const tree = apiFullResponse.value ? fullTree : stripFolderTreeContent(fullTree);
      if (!apiFullResponse.value) return tree;
      const countTree = node => {
        let folders = 1;
        let sections = Array.isArray(node?.sections) ? node.sections.length : 0;
        let data_sets = Array.isArray(node?.data_sets) ? node.data_sets.length : 0;
        let forms = Array.isArray(node?.forms) ? node.forms.length : 0;
        for (const child of (node?.folders || [])) { const c=countTree(child); folders+=c.folders; sections+=c.sections; data_sets+=c.data_sets; forms+=c.forms; }
        return {folders,sections,data_sets,forms};
      };
      const counts = countTree(tree);
      return {
        data: tree,
        meta: {
          project:{name:currentProject.value?.name || 'Проект',slug:currentProject.value?.slug || state.project_api_namespace || 'main'},
          type:'folder-tree',
          name:currentFolder.value?.name || tree.name || 'Папка',
          slug:currentFolder.value?.slug || tree.slug || '',
          recursive:true,
          cache:{enabled:Number(folderApiSettings.cache_ttl || 0)>0,ttl:Number(folderApiSettings.cache_ttl || 0),status:'preview'},
          folders:counts.folders,
          sections:counts.sections,
          data_sets:counts.data_sets,
          forms:counts.forms,
          response_mode:'full',
          language:state.i18n?.default_language || 'ru',
          path:tree.path || '',
          url:tree.url || folderEndpoint.value,
        },
      };
    });

    const currentApiResponsePreview = computed(() => currentDocument.value ? documentResponsePreview.value : (currentFolder.value ? folderResponsePreview.value : projectResponsePreview.value));
    const currentApiResponseJson = computed(() => apiResponseJson(currentApiResponsePreview.value));
    const documentResponseJson = computed(() => apiResponseJson(documentResponsePreview.value));
    const folderResponseJson = computed(() => apiResponseJson(folderResponsePreview.value));
    const dataResponseJson = computed(() => apiResponseJson(dataResponsePreview.value));
    const formResponseJson = computed(() => apiResponseJson(formResponsePreview.value));

    const promptJsonBlock = (value, label = 'JSON пример') => {
      let json = JSON.stringify(value, null, 2) || '{}';
      if (json.length > 6500) json = json.slice(0, 6490) + '\n…';
      return `\n\n${label}:\n\`\`\`json\n${json}\n\`\`\``;
    };

    const apiAiPrompt = computed(() => {
      const endpoint = currentDocument.value ? absoluteApiUrl.value : (currentFolder.value ? folderEndpoint.value : absoluteApiRoot.value);
      const responseType = currentDocument.value
        ? (apiFullResponse.value ? 'JSON-объект { data, meta }' : (currentDocument.value.mode === 'multiple' ? 'JSON-массив объектов' : 'JSON-объект'))
        : currentFolder.value
          ? (apiFullResponse.value ? 'рекурсивное дерево папки в { data, meta }' : 'рекурсивное JSON-дерево папки с folders и sections')
          : 'JSON с доступными endpoint проекта';
      return [
        'Помоги мне правильно подключить MaterCMS API к моему проекту.',
        '',
        `Endpoint: GET ${endpoint}`,
        `Ответ: ${responseType}.`,
        aiAuthInstruction.value,
        '',
        'Требования:',
        '- используй этот endpoint как источник данных и не придумывай другой URL;',
        '- добавь обработку HTTP-ошибок, loading и empty state;',
        '- не используй POST/PUT/PATCH/DELETE для изменения контента: внешний контентный API MaterCMS предназначен для чтения;',
        '- если проект приватный, секрет должен оставаться только на сервере;',
        '- дай production-ready код целиком, а не отдельные фрагменты;',
        `- формат ответа проекта: ${apiFullResponse.value ? 'полный — объект data + meta' : 'компактный — только данные без meta'};`,
        '- используй JSON-пример ниже как фактическую структуру ответа и не придумывай другие поля;',
        '- если я ещё не указал стек проекта, сначала коротко спроси, какой язык или framework я использую.',
        promptJsonBlock(currentDocument.value ? documentResponsePreview.value : (currentFolder.value ? folderResponsePreview.value : projectResponsePreview.value)),
      ].join('\n');
    });

    const dataAiPrompt = computed(() => {
      const set = currentDataSet.value;
      const responseType = apiFullResponse.value ? 'JSON-объект { data, meta }' : (set?.mode === 'multiple' ? 'JSON-массив объектов' : 'JSON-объект');
      return [
        `Помоги подключить набор данных MaterCMS «${set?.name || 'Данные'}» к моему проекту.`,
        '',
        `Endpoint: GET ${dataEndpoint.value}`,
        `Ответ: ${responseType}.`,
        'Этот endpoint read-only: создание, изменение и удаление данных снаружи запрещены.',
        'Если в данных есть поля «Связь», MaterCMS раскрывает связанные записи на один уровень.',
        aiAuthInstruction.value,
        '',
        `Формат ответа проекта: ${apiFullResponse.value ? 'полный — объект data + meta' : 'компактный — только данные без meta'}.`,
        'Сделай production-ready интеграцию: загрузка, обработка ошибок, типичный вывод данных и понятная структура кода. Используй JSON-пример ниже как структуру ответа и не придумывай поля, которых там нет. Если стек не указан — сначала спроси мой язык/framework.',
        promptJsonBlock(dataResponsePreview.value),
      ].join('\n');
    });

    const formAiPrompt = computed(() => {
      const fields = Array.isArray(currentForm.value?.schema) ? currentForm.value.schema : [];
      const fieldLines = fields.length
        ? fields.map(field => `- ${field.key}: ${field.type}${field.required ? ' (обязательное)' : ''}${field.multiple ? ' (несколько файлов)' : ''}`).join('\n')
        : '- форма пока без полей';
      const auth = state.api_access?.mode === 'private'
        ? 'Проект приватный. POST должен идти через backend/serverless. MATERCMS_API_TOKEN хранить только в переменной окружения и передавать как Authorization: Bearer <token>. Никогда не вставлять токен в HTML/браузерный JavaScript.'
        : 'Проект публичный, поэтому форму можно отправлять напрямую в MaterCMS без Bearer-токена.';
      return [
        `Помоги подключить форму MaterCMS «${currentForm.value?.name || 'Форма'}» к моему сайту.`,
        '',
        `Endpoint: POST ${formEndpoint.value}`,
        auth,
        '',
        'Поля формы:',
        fieldLines,
        '',
        `Ответ MaterCMS: ${apiFullResponse.value ? 'полный — ok, message, submission_id, project и form' : 'компактный — только ok и message'}.`,
        'Нужно сделать полноценную отправку формы: состояния loading/success/error, корректную валидацию, multipart/form-data при наличии файлов и honeypot _website. Используй именно указанный endpoint. Если мой стек не указан — сначала спроси язык/framework, затем дай готовый код целиком.',
        promptJsonBlock(formPromptRequestExample.value, 'JSON запроса'),
        promptJsonBlock(formResponsePreview.value, 'JSON успешного ответа'),
      ].join('\n');
    });

    const chatGptPromptUrl = prompt => `https://chatgpt.com/?prompt=${encodeURIComponent(String(prompt || ''))}`;

    const modalTitle = computed(() => ({
      'create-folder': 'Новая папка',
      'create-document': 'Новый раздел',
      'create-form': 'Новая форма',
      'create-data': 'Новые данные',
      'create-project': 'Новый проект',
      'rename-project': 'Название проекта',
      'create-user': 'Новый пользователь',
      'edit-user': 'Пользователь',
      'api-token': 'Секретный API-токен',
      'database-switch': 'Сменить базу данных',
      'content-properties': 'Свойства',
      'link-resource': 'Добавить в папку',
      rename: 'Переименовать',
    }[modal.value] || ''));

    const modalEyebrow = computed(() => ({
      'create-folder': 'ОРГАНИЗАЦИЯ',
      'create-document': 'КОНТЕНТ',
      'create-form': 'ФОРМА',
      'create-data': 'ДАННЫЕ',
      'create-project': 'ПРОЕКТ',
      'rename-project': 'ПРОЕКТ',
      'create-user': 'ДОСТУП',
      'edit-user': 'ДОСТУП',
      'api-token': 'ПРИВАТНЫЙ API',
      'database-switch': 'СИСТЕМА',
      'content-properties': 'МОЙ КОНТЕНТ',
      'link-resource': 'СВЯЗАННЫЙ РЕСУРС',
      rename: 'НАЗВАНИЕ',
    }[modal.value] || ''));

    const contextMenuStyle = computed(() => contextMenu.value ? {
      left: `${contextMenu.value.x}px`,
      top: `${contextMenu.value.y}px`,
    } : {});

    const smartContextMenuTitle = computed(() => {
      const menu = contextMenu.value;
      if (!menu) return '';
      const item = menu.item || {};
      if (menu.type === 'page') {
        return ({
          folder: currentFolder.value?.name || 'Мой контент',
          files: 'Файлы', data: 'Данные', 'data-set': currentDataSet.value?.name || 'Данные',
          forms: 'Формы', form: currentForm.value?.name || 'Форма',
          'settings-projects': 'Проекты', 'settings-team': 'Команда', settings: 'Настройки', database: 'База данных',
          document: currentDocument.value?.name || 'Раздел',
        })[route.kind] || 'MaterCMS';
      }
      if (menu.type === 'media') return item.name || 'Файл';
      if (['folder','document','resource-link'].includes(menu.type) && isContentSelected(menu.type,item) && contentSelection.value.length > 1) return `${contentSelection.value.length} объектов`;
      if (menu.type === 'folder' || menu.type === 'document' || menu.type === 'resource-link' || menu.type === 'data' || menu.type === 'form' || menu.type === 'project' || menu.type === 'user') return item.name || 'Объект';
      if (menu.type === 'record-data') return dataItemTitle(item, Number(menu.index || 0));
      if (menu.type === 'record-document') return itemTitle(item, Number(menu.index || 0));
      if (menu.type === 'submission') return item.preview || `Заявка #${item.id || ''}`;
      if (menu.type === 'member') return item.name || 'Участник';
      return 'Действия';
    });

    const smartContextMenuEyebrow = computed(() => {
      const type = contextMenu.value?.type || '';
      return ({page:'ТЕКУЩАЯ СТРАНИЦА',folder:'ПАПКА',document:'РАЗДЕЛ','resource-link':'СВЯЗАННЫЙ РЕСУРС',media:'ФАЙЛ',data:'ДАННЫЕ',form:'ФОРМА',project:'ПРОЕКТ',user:'ПОЛЬЗОВАТЕЛЬ','record-data':'ЗАПИСЬ','record-document':'ЗАПИСЬ',submission:'ЗАЯВКА',member:'УЧАСТНИК'})[type] || 'MATER';
    });

    const smartContextActions = computed(() => {
      const menu = contextMenu.value;
      if (!menu) return [];
      const item = menu.item || {};
      const actions = [];
      const add = (id, label, icon, options = {}) => actions.push({ id, label, icon, ...options });

      if (menu.type === 'folder') {
        const multi = isContentSelected('folder',item) && contentSelection.value.length > 1;
        if (!multi) add('open-folder','Открыть','bi-folder2-open');
        if (!multi) add('open-new-tab','Открыть в новой вкладке','bi-box-arrow-up-right');
        if (!multi) add('folder-api','API папки','bi-braces');
        add('copy-content',multi ? `Копировать (${contentSelection.value.length})` : 'Копировать','bi-copy',{shortcut:'Ctrl+C',separatorBefore:true});
        if (can('content.edit')) {
          if (contentClipboard.value && !multi) add('paste-into-folder','Вставить в папку','bi-clipboard-check',{shortcut:'Ctrl+V'});
          add('duplicate-content','Создать копию','bi-files',{shortcut:'Ctrl+D'});
          if (!multi) add('rename','Переименовать','bi-pencil-square',{separatorBefore:true,shortcut:'F2'});
          add('delete','В корзину','bi-trash3',{danger:true,separatorBefore:multi});
        }
        if (!multi) add('copy-path','Копировать путь','bi-signpost-split',{separatorBefore:true});
        if (!multi) add('properties','Свойства','bi-info-square',{shortcut:'Alt+Enter'});
      } else if (menu.type === 'document') {
        const multi = isContentSelected('document',item) && contentSelection.value.length > 1;
        if (!multi) add('open-document','Открыть','bi-file-earmark-text');
        if (!multi) add('open-new-tab','Открыть в новой вкладке','bi-box-arrow-up-right');
        if (!multi) add('document-api','API раздела','bi-braces');
        add('copy-content',multi ? `Копировать (${contentSelection.value.length})` : 'Копировать','bi-copy',{shortcut:'Ctrl+C',separatorBefore:true});
        if (can('content.edit')) {
          add('duplicate-content','Создать копию','bi-files',{shortcut:'Ctrl+D'});
          if (!multi) add('rename','Переименовать','bi-pencil-square',{separatorBefore:true,shortcut:'F2'});
          add('delete','В корзину','bi-trash3',{danger:true,separatorBefore:multi});
        }
        if (!multi) add('copy-path','Копировать путь','bi-signpost-split',{separatorBefore:true});
        if (!multi) add('properties','Свойства','bi-info-square',{shortcut:'Alt+Enter'});
      } else if (menu.type === 'resource-link') {
        const multi = isContentSelected('resource-link',item) && contentSelection.value.length > 1;
        if (!multi) add('open-linked-resource',item.resource_type === 'form' ? 'Открыть форму' : 'Открыть данные',item.resource_type === 'form' ? 'bi-ui-checks-grid' : 'bi-database');
        if (!multi) add('linked-resource-api','Открыть API','bi-braces');
        add('copy-content',multi ? `Копировать (${contentSelection.value.length})` : 'Копировать','bi-copy',{shortcut:'Ctrl+C',separatorBefore:true});
        if (can('content.edit')) {
          add('duplicate-content','Создать связь в другой папке','bi-files',{shortcut:'Ctrl+D'});
          add('delete','Убрать из папки','bi-link-45deg',{danger:true,separatorBefore:true});
        }
        if (!multi) add('copy-path','Копировать путь','bi-signpost-split',{separatorBefore:true});
        if (!multi) add('properties','Свойства','bi-info-square',{shortcut:'Alt+Enter'});
      } else if (menu.type === 'media') {
        add('preview-file','Предпросмотр','bi-eye');
        add('open-file-usage',fileUsageLabel(item),'bi-arrow-up-right-square',{disabled:!item.document_id && !item.data_set_id && !item.form_id});
        add('copy-file-url','Копировать ссылку','bi-link-45deg',{separatorBefore:true});
        add('open-file-original','Открыть оригинал','bi-box-arrow-up-right');
      } else if (menu.type === 'data') {
        add('open-data','Открыть данные','bi-database');
        add('data-api','Открыть API','bi-braces');
        if (can('data.edit')) add('delete-data','Удалить данные','bi-trash3',{danger:true,separatorBefore:true});
      } else if (menu.type === 'form') {
        add('open-form','Открыть форму','bi-ui-checks-grid');
        add('form-api','Подключение / API','bi-plug');
        if (can('forms.edit')) add('delete-form','Удалить форму','bi-trash3',{danger:true,separatorBefore:true});
      } else if (menu.type === 'project') {
        if (Number(item.id) !== Number(currentProject.value?.id)) add('open-project','Открыть проект','bi-arrow-right-circle');
        else add('project-current','Текущий проект','bi-check-circle',{disabled:true});
        if (Number(item.id) === Number(currentProject.value?.id) && can('project.edit')) add('rename-project','Переименовать','bi-pencil-square',{separatorBefore:true});
        if (Number(item.id) === Number(currentProject.value?.id) && isSystemOwner.value && projects.value.length > 1) add('delete-project','Удалить проект','bi-trash3',{danger:true});
      } else if (menu.type === 'user') {
        add('edit-user','Редактировать','bi-pencil-square');
        const protectedUser = item.system_role === 'owner' || Number(item.id) === Number(state.user?.id);
        add('delete-user','Удалить пользователя','bi-trash3',{danger:true,separatorBefore:true,disabled:protectedUser});
      } else if (menu.type === 'record-data' || menu.type === 'record-document') {
        add('record-read','Читаемый вид','bi-eye');
        if ((menu.type === 'record-data' && can('data.edit')) || (menu.type === 'record-document' && can('content.edit'))) {
          add('record-edit','Редактировать','bi-pencil-square');
          add('record-delete','Удалить','bi-trash3',{danger:true,separatorBefore:true});
        }
      } else if (menu.type === 'submission') {
        add('open-submission','Открыть заявку','bi-inbox');
        if (can('forms.edit')) add('delete-submission','Удалить заявку','bi-trash3',{danger:true,separatorBefore:true});
      } else if (menu.type === 'member') {
        if (can('members.manage')) {
          add('edit-member','Права участника','bi-sliders2');
          if (item.role !== 'owner') add('remove-member','Убрать из проекта','bi-person-dash',{danger:true,separatorBefore:true});
        } else add('member-no-access','Нет доступных действий','bi-lock',{disabled:true});
      } else if (menu.type === 'page') {
        if (route.kind === 'folder') {
          if (can('content.edit')) {
            add('create-folder','Новая папка','bi-folder-plus',{shortcut:'Ctrl+Shift+N'});
            add('create-document','Новый раздел','bi-file-earmark-plus');
            add('paste-content',contentClipboard.value ? `Вставить (${contentClipboardCount.value})` : 'Вставить','bi-clipboard-check',{shortcut:'Ctrl+V',separatorBefore:true,disabled:!contentClipboard.value || clipboardBusy.value});
          }
          add('select-all','Выбрать всё','bi-check2-square',{shortcut:'Ctrl+A',separatorBefore:true});
          if (contentOrderedItems.value.length) add('invert-selection','Инвертировать выделение','bi-intersect');
          add('undo-explorer','Отменить','bi-arrow-counterclockwise',{shortcut:'Ctrl+Z',disabled:!explorerHistory.value.length});
          add('redo-explorer','Повторить','bi-arrow-clockwise',{shortcut:'Ctrl+Y',disabled:!explorerRedo.value.length});
          add(viewMode.value === 'grid' ? 'view-list' : 'view-grid', viewMode.value === 'grid' ? 'Показать списком' : 'Показать сеткой', viewMode.value === 'grid' ? 'bi-list-ul' : 'bi-grid',{separatorBefore:true});
          add('sort-name','Сортировать по имени','bi-sort-alpha-down');
          add('sort-updated','Сортировать по дате','bi-calendar3');
          add('refresh','Обновить','bi-arrow-clockwise',{separatorBefore:true});
          add('trash','Корзина','bi-trash',{separatorBefore:true});
        } else if (route.kind === 'files') {
          add(viewMode.value === 'grid' ? 'view-list' : 'view-grid', viewMode.value === 'grid' ? 'Показать списком' : 'Показать сеткой', viewMode.value === 'grid' ? 'bi-list-ul' : 'bi-grid');
          add('refresh','Обновить файлы','bi-arrow-clockwise');
          if (can('files.manage') && mediaFiles.value.some(file => Number(file.reference_count || 0) === 0)) add('cleanup-files','Очистить неиспользуемые','bi-trash3',{danger:true,separatorBefore:true});
        } else if (route.kind === 'data') {
          if (can('data.edit')) add('create-data','Создать данные','bi-database-add');
          add('refresh','Обновить','bi-arrow-clockwise',{separatorBefore:can('data.edit')});
        } else if (route.kind === 'data-set') {
          if (currentDataSet.value?.mode === 'multiple' && can('data.edit')) add('create-record-data','Новая запись','bi-plus-circle');
          add('open-data-api','API','bi-braces',{separatorBefore:currentDataSet.value?.mode === 'multiple' && can('data.edit')});
          if (can('data.edit')) add('open-data-fields','Поля','bi-sliders2');
          if (currentDataSet.value?.mode === 'multiple') add(dataRecordViewMode.value === 'grid' ? 'data-view-list' : 'data-view-grid',dataRecordViewMode.value === 'grid' ? 'Показать списком' : 'Показать сеткой',dataRecordViewMode.value === 'grid' ? 'bi-list-ul' : 'bi-grid',{separatorBefore:true});
        } else if (route.kind === 'forms') {
          if (can('forms.edit')) add('create-form','Новая форма','bi-ui-checks-grid');
          add('refresh','Обновить','bi-arrow-clockwise',{separatorBefore:can('forms.edit')});
        } else if (route.kind === 'form') {
          add('open-form-api','Подключение / API','bi-plug');
          add('open-form-fields','Поля формы','bi-sliders2');
          if (formTab.value === 'submissions') add('refresh-submissions','Обновить заявки','bi-arrow-clockwise',{separatorBefore:true});
        } else if (route.kind === 'document') {
          if (currentDocument.value?.mode === 'multiple' && can('content.edit')) add('create-record-document','Новая запись','bi-plus-circle');
          add('open-document-api','API','bi-braces',{separatorBefore:currentDocument.value?.mode === 'multiple' && can('content.edit')});
          if (can('content.edit')) add('open-document-fields','Поля','bi-sliders2');
          add('open-versions','Версии','bi-clock-history');
          if (currentDocument.value?.mode === 'multiple') add(documentRecordViewMode.value === 'grid' ? 'document-view-list' : 'document-view-grid',documentRecordViewMode.value === 'grid' ? 'Показать списком' : 'Показать сеткой',documentRecordViewMode.value === 'grid' ? 'bi-list-ul' : 'bi-grid',{separatorBefore:true});
        } else if (route.kind === 'settings') {
          add('go-api-settings','Доступ к API','bi-braces');
          add('go-language-settings','Языки проекта','bi-translate');
          add('go-projects','Проекты','bi-layers');
          if (isSystemOwner.value) add('go-team-settings','Команда','bi-people');
          add('go-database','База данных','bi-database-gear');
        } else if (['settings-api','settings-languages'].includes(route.kind)) {
          add('go-settings','Назад в настройки','bi-arrow-left');
          add('refresh','Обновить','bi-arrow-clockwise');
        } else if (route.kind === 'settings-projects') {
          add('go-settings','Назад в настройки','bi-arrow-left');
          if (isSystemOwner.value) add('create-project','Новый проект','bi-plus-circle',{separatorBefore:true});
          if (can('members.manage')) add('add-member','Добавить участника','bi-person-plus',{separatorBefore:true});
          add('refresh','Обновить проекты и доступ','bi-arrow-clockwise',{separatorBefore:!isSystemOwner.value && !can('members.manage')});
        } else if (route.kind === 'settings-team') {
          add('go-settings','Назад в настройки','bi-arrow-left');
          if (isSystemOwner.value) add('create-user','Новый пользователь','bi-person-plus',{separatorBefore:true});
          add('refresh','Обновить пользователей','bi-arrow-clockwise');
        } else if (route.kind === 'database') {
          add('go-settings','Назад в настройки','bi-arrow-left');
          add('refresh','Обновить состояние','bi-arrow-clockwise');
          if (isSystemOwner.value) add('switch-database','Сменить базу','bi-arrow-left-right',{separatorBefore:true});
        }
      }
      return actions;
    });

    const createMenuStyle = computed(() => createMenu.value ? {
      left: `${createMenu.value.x}px`,
      top: `${createMenu.value.y}px`,
    } : {});

    const notify = (message, type = 'success') => {
      const id = ++toastId;
      toasts.value.push({ id, message, type });
      window.setTimeout(() => {
        toasts.value = toasts.value.filter(toast => toast.id !== id);
      }, 2800);
    };

    const askConfirm = ({
      title = 'Подтвердите действие',
      message = 'Вы уверены?',
      confirmLabel = 'Подтвердить',
      cancelLabel = 'Отмена',
      tone = 'danger',
      icon = 'bi-exclamation-triangle',
    } = {}) => new Promise(resolve => {
      if (typeof confirmDialog.resolve === 'function') confirmDialog.resolve(false);
      confirmDialog.title = title;
      confirmDialog.message = message;
      confirmDialog.confirmLabel = confirmLabel;
      confirmDialog.cancelLabel = cancelLabel;
      confirmDialog.tone = tone;
      confirmDialog.icon = icon;
      confirmDialog.resolve = resolve;
      confirmDialog.open = true;
      nextTick(() => document.querySelector('.confirm-modal .confirm-primary')?.focus());
    });

    const closeConfirm = result => {
      const resolve = confirmDialog.resolve;
      confirmDialog.open = false;
      confirmDialog.resolve = null;
      if (typeof resolve === 'function') resolve(Boolean(result));
    };

    const parseResponse = async response => {
      let payload = {};
      try { payload = await response.json(); } catch (_) {}
      if (!response.ok || payload.ok === false) {
        const error = new Error(payload.error || `HTTP ${response.status}`);
        error.status = response.status;
        throw error;
      }
      return payload;
    };

    const apiGet = async (action, params = {}) => {
      const query = new URLSearchParams({ action, ...params });
      if (state.current_project?.id && !query.has('project_id')) query.set('project_id', state.current_project.id);
      const response = await fetch(`${config.adminApi}?${query}`, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });
      return parseResponse(response);
    };

    const apiPost = async (action, payload = {}) => {
      const response = await fetch(`${config.adminApi}?action=${encodeURIComponent(action)}`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-Token': state.csrf,
        },
        body: JSON.stringify({ ...(state.current_project?.id ? { project_id: state.current_project.id } : {}), ...payload }),
      });
      return parseResponse(response);
    };


    const runGlobalSearch = async () => {
      const query = globalSearchQuery.value.trim();
      if (query.length < 2) {
        globalSearchResults.value = [];
        globalSearchLoading.value = false;
        return;
      }
      const serial = ++globalSearchSerial;
      globalSearchLoading.value = true;
      try {
        const payload = await apiGet('global_search', { q: query });
        if (serial !== globalSearchSerial) return;
        globalSearchResults.value = Array.isArray(payload.results) ? payload.results : [];
      } catch (error) {
        if (serial !== globalSearchSerial) return;
        globalSearchResults.value = [];
      } finally {
        if (serial === globalSearchSerial) globalSearchLoading.value = false;
      }
    };

    const openGlobalSearch = () => {
      searchFocused.value = true;
      globalSearchOpen.value = true;
      if (globalSearchQuery.value.trim().length >= 2 && !globalSearchResults.value.length) runGlobalSearch();
    };

    const closeGlobalSearch = () => {
      globalSearchOpen.value = false;
      searchFocused.value = false;
    };

    const resetGlobalSearch = ({ blur = true } = {}) => {
      if (globalSearchTimer) window.clearTimeout(globalSearchTimer);
      globalSearchTimer = null;
      // Invalidate an already running request so a late response cannot reopen or
      // repopulate the search overlay after navigation.
      globalSearchSerial += 1;
      globalSearchLoading.value = false;
      globalSearchOpen.value = false;
      searchFocused.value = false;
      globalSearchResults.value = [];
      globalSearchQuery.value = '';
      if (blur) nextTick(() => {
        const input = document.querySelector('.global-search input');
        if (input && document.activeElement === input) input.blur();
      });
    };

    const clearGlobalSearch = () => {
      globalSearchQuery.value = '';
      globalSearchResults.value = [];
      globalSearchLoading.value = false;
      globalSearchOpen.value = true;
      searchFocused.value = true;
      nextTick(() => document.querySelector('.global-search input')?.focus());
    };

    watch([drawer, () => currentFolder.value?.id], ([open]) => {
      if (open === 'api' && currentFolder.value?.id) loadFolderApiPreview();
      else if (!currentFolder.value?.id) folderApiPreview.value = null;
    });

    watch(globalSearchQuery, () => {
      if (globalSearchTimer) window.clearTimeout(globalSearchTimer);
      globalSearchTimer = null;
      const query = globalSearchQuery.value.trim();
      if (query.length < 2) {
        globalSearchResults.value = [];
        globalSearchLoading.value = false;
        return;
      }
      // Only typing in the focused search field is allowed to open the overlay.
      // Programmatic query resets during navigation must never reopen it.
      if (searchFocused.value) globalSearchOpen.value = true;
      globalSearchTimer = window.setTimeout(runGlobalSearch, 220);
    });

    const applyState = payload => {
      state.user = payload.user || state.user;
      state.folders = Array.isArray(payload.folders) ? payload.folders : [];
      state.documents = Array.isArray(payload.documents) ? payload.documents : [];
      state.content_links = Array.isArray(payload.content_links) ? payload.content_links : [];
      state.csrf = payload.csrf || state.csrf;
      state.base = payload.base ?? state.base;
      state.public_api = payload.public_api || state.public_api;
      state.public_api_absolute = payload.public_api_absolute || state.public_api_absolute;
      state.project_api_namespace = payload.project_api_namespace || state.project_api_namespace;
      if (Array.isArray(payload.projects)) { state.projects = payload.projects; projects.value = payload.projects; }
      if (payload.current_project) { state.current_project = payload.current_project; localStorage.setItem('feather-project-id', String(payload.current_project.id)); }
      if (payload.permissions) state.permissions = payload.permissions;
      if (payload.api_access) state.api_access = payload.api_access;
      if (payload.api_status) state.api_status = payload.api_status;
      if (payload.api_response) state.api_response = payload.api_response;
      if (payload.i18n) state.i18n = payload.i18n;
      if (payload.trash_count !== undefined) state.trash_count = Number(payload.trash_count || 0);
    };


    const sameJson = (a, b) => {
      try { return JSON.stringify(a) === JSON.stringify(b); }
      catch (_) { return false; }
    };

    // Autosave responses are acknowledgements, not a fresh source of truth for the editor.
    // Replacing currentDocument/currentForm/currentDataSet here destroys focus/caret and can
    // overwrite keystrokes made while the request was in flight. Only non-editable metadata
    // and list summaries are merged in the background.
    const syncContentLinkSummaries = (type, items) => {
      if (!Array.isArray(items) || !Array.isArray(state.content_links)) return;
      const map = new Map(items.map(item => [Number(item.id),item]));
      state.content_links = state.content_links.map(link => {
        if (link.resource_type !== type) return link;
        const source = map.get(Number(link.resource_id));
        if (!source) return link;
        return {
          ...link,
          name: source.name ?? link.name,
          slug: source.slug ?? link.slug,
          mode: source.mode ?? link.mode,
          api_enabled: source.api_enabled !== false,
          item_count: source.item_count ?? link.item_count,
          updated_at: source.updated_at ?? link.updated_at,
          endpoint: source.endpoint ?? link.endpoint,
        };
      });
    };

    const mergeDocumentBackgroundState = payload => {
      const remoteState = payload?.state;
      if (Array.isArray(remoteState?.documents)) state.documents = remoteState.documents;
      if (Array.isArray(remoteState?.folders)) state.folders = remoteState.folders;
      if (Array.isArray(remoteState?.content_links)) state.content_links = remoteState.content_links;
      if (payload?.document && currentDocument.value && Number(payload.document.id) === Number(currentDocument.value.id)) {
        currentDocument.value.updated_at = payload.document.updated_at || currentDocument.value.updated_at;
        currentDocument.value.version_count = payload.document.version_count ?? currentDocument.value.version_count;
        currentDocument.value.version_counts = payload.document.version_counts ?? currentDocument.value.version_counts;
        currentDocument.value.api_path = payload.document.api_path || currentDocument.value.api_path;
        currentDocument.value.api_enabled = payload.document.api_enabled !== false;
        if (payload.document.api_sample !== undefined) currentDocument.value.api_sample = payload.document.api_sample;
      }
    };

    const mergeFormBackgroundState = payload => {
      if (Array.isArray(payload?.forms)) { forms.value = payload.forms; syncContentLinkSummaries('form',payload.forms); }
      const remote = payload?.form;
      const local = currentForm.value;
      if (!remote || !local || Number(remote.id) !== Number(local.id)) return;
      local.updated_at = remote.updated_at || local.updated_at;
      local.api_enabled = remote.api_enabled !== false;
      local.endpoint = remote.endpoint || local.endpoint;
      local.slug = remote.slug || local.slug;
      local.new_count = remote.new_count ?? local.new_count;
      local.submission_count = remote.submission_count ?? local.submission_count;
    };

    const mergeDataBackgroundState = payload => {
      if (Array.isArray(payload?.data_sets)) { dataSets.value = payload.data_sets; syncContentLinkSummaries('data',payload.data_sets); }
      const remote = payload?.data_set;
      const local = currentDataSet.value;
      if (!remote || !local || Number(remote.id) !== Number(local.id)) return;
      local.updated_at = remote.updated_at || local.updated_at;
      local.api_enabled = remote.api_enabled !== false;
      local.api_path = remote.api_path || local.api_path;
      local.api_url = remote.api_url || local.api_url;
      if (remote.api_sample !== undefined) local.api_sample = remote.api_sample;
      // Multiple records receive stable server IDs. Merge only this server-owned metadata by
      // _uid; never replace the user's values or schema while they are editing.
      if (local.mode === 'multiple' && Array.isArray(local.data) && Array.isArray(remote.data)) {
        const remoteByUid = new Map(remote.data.map(item => [String(item?._uid || ''), item]));
        for (const item of local.data) {
          const saved = remoteByUid.get(String(item?._uid || ''));
          if (saved && Number(saved._id || 0) > 0) item._id = Number(saved._id);
        }
      }
    };

    const loadState = async () => {
      const savedProject = Number(localStorage.getItem('feather-project-id') || 0);
      const payload = await apiGet('state', savedProject > 0 ? { project_id: savedProject } : {});
      applyState(payload);
    };

    const loadFiles = async () => {
      filesLoading.value = true;
      try {
        const payload = await apiGet('files');
        mediaFiles.value = Array.isArray(payload.files) ? payload.files : [];
      } finally {
        filesLoading.value = false;
      }
    };

    const makeUid = () => `i${Date.now().toString(36)}${Math.random().toString(36).slice(2, 9)}`;
    const uploadTypes = ['image','video','audio','file'];
    const isUploadType = type => uploadTypes.includes(type);
    const defaultValue = field => {
      const type = typeof field === 'string' ? field : field?.type;
      const multiple = typeof field === 'object' && isUploadType(type) && field?.multiple === true;
      if (multiple) return [];
      if (type === 'relation') return typeof field === 'object' && field?.relation_multiple === true ? [] : null;
      if (type === 'boolean') return false;
      if (type === 'number') return null;
      return '';
    };
    const normalizeFieldValue = (field, value) => {
      if (field?.type === 'relation') {
        const ids = (Array.isArray(value) ? value : (value === null || value === undefined || value === '' ? [] : [value]))
          .map(item => Number(item)).filter(item => Number.isInteger(item) && item > 0);
        return field?.relation_multiple === true ? [...new Set(ids)] : (ids[0] ?? null);
      }
      if (isUploadType(field?.type) && field?.multiple === true) {
        if (Array.isArray(value)) return value.filter(item => typeof item === 'string' && item);
        return value ? [value] : [];
      }
      if (Array.isArray(value)) value = value.find(item => item !== null && item !== undefined && item !== '') ?? '';
      return value === undefined ? defaultValue(field) : value;
    };

    const hydrateDataset = (doc, raw, fallbackRows = null) => {
      if (doc.mode === 'multiple') {
        const rows = Array.isArray(raw) ? raw : [];
        return rows.map((row, index) => {
          const item = row && typeof row === 'object' && !Array.isArray(row) ? { ...row } : {};
          const fallbackUid = Array.isArray(fallbackRows) ? fallbackRows[index]?._uid : null;
          item._uid = String(item._uid || fallbackUid || makeUid());
          for (const field of doc.schema) item[field.key] = normalizeFieldValue(field, item[field.key]);
          return item;
        });
      }
      const data = raw && typeof raw === 'object' && !Array.isArray(raw) ? { ...raw } : {};
      for (const field of doc.schema) data[field.key] = normalizeFieldValue(field, data[field.key]);
      return data;
    };

    const hydrateDocument = doc => {
      doc.mode = doc.mode === 'multiple' ? 'multiple' : 'single';
      doc.api_enabled = doc.api_enabled !== false;
      doc.schema = Array.isArray(doc.schema) ? doc.schema.map((field, index) => ({
        ...field,
        multiple: isUploadType(field?.type) ? field?.multiple === true : false,
        _uid: `${Date.now()}-${index}-${Math.random()}`,
      })) : [];
      doc.i18n = doc.i18n || state.i18n || { enabled:false, default_language:'ru', languages:['ru'] };
      doc.data = hydrateDataset(doc, doc.data);
      const rawTranslations = doc.translations && typeof doc.translations === 'object' ? doc.translations : {};
      doc.translations = {};
      for (const [code, raw] of Object.entries(rawTranslations)) {
        doc.translations[code] = hydrateDataset(doc, raw, doc.mode === 'multiple' ? doc.data : null);
      }
      if (doc.mode === 'multiple') activeItemUid.value = doc.data[0]?._uid || null;
      else activeItemUid.value = null;
      return doc;
    };

    const ensureTranslation = language => {
      const doc = currentDocument.value;
      if (!doc || !doc.i18n?.enabled) return;
      const defaultLanguage = doc.i18n.default_language || 'ru';
      if (language === defaultLanguage) return;
      doc.translations ||= {};
      if (doc.translations[language] !== undefined) return;
      doc.translations[language] = JSON.parse(JSON.stringify(doc.data));
    };

    const switchEditorLanguage = async language => {
      const doc = currentDocument.value;
      if (!doc) return;
      const available = doc.i18n?.enabled ? (doc.i18n.languages || []) : [doc.i18n?.default_language || 'ru'];
      if (!available.includes(language) || editorLanguage.value === language) return;
      if (!(await flushAutosave())) return;
      ensureTranslation(language);
      editorLanguage.value = language;
      const dataset = activeDocumentData.value;
      activeItemUid.value = doc.mode === 'multiple' && Array.isArray(dataset) ? (dataset[0]?._uid || null) : null;
      versions.value = [];
      if (drawer.value === 'versions') await loadVersions();
    };

    const allDocumentDatasets = () => {
      const doc = currentDocument.value;
      if (!doc) return [];
      return [doc.data, ...Object.values(doc.translations || {})].filter(dataset => dataset && typeof dataset === 'object');
    };


    const clearAutosaveTimer = () => {
      if (autosaveTimer) window.clearTimeout(autosaveTimer);
      autosaveTimer = null;
    };

    const loadDocument = async id => {
      const session = ++documentSession;
      clearAutosaveTimer();
      activeSavePromise = null;
      saving.value = false;
      currentDocument.value = null;
      cleanupPreviews();
      const payload = await apiGet('document', { id: String(id) });
      if (session !== documentSession) return;
      currentDocument.value = hydrateDocument(payload.document);
      if (!Object.keys(languageCatalog.value).length && currentDocument.value?.i18n?.enabled) { try { const settingsPayload = await apiGet('settings'); languageCatalog.value = settingsPayload.languages || {}; } catch (_) {} }
      editorLanguage.value = currentDocument.value?.i18n?.default_language || 'ru';
      if (currentDocument.value?.mode === 'multiple') activeItemUid.value = activeDocumentData.value?.[0]?._uid || null;
      dirty.value = false;
      saveState.value = 'saved';
      lastSavedAt.value = payload.document?.updated_at || null;
      versions.value = [];
      changeSerial = 0;
    };

    const hydrateForm = form => {
      form.api_enabled = form.api_enabled !== false;
      form.schema = Array.isArray(form.schema) ? form.schema.map((field, index) => ({
        ...field,
        required: field?.required === true,
        multiple: field?.type === 'file' && field?.multiple === true,
        options: Array.isArray(field?.options) ? field.options : [],
        optionsText: Array.isArray(field?.options) ? field.options.join('\n') : '',
        _uid: `form-${Date.now()}-${index}-${Math.random()}`,
      })) : [];
      return form;
    };

    const loadForms = async () => {
      formsLoading.value = true;
      try {
        const payload = await apiGet('forms');
        forms.value = Array.isArray(payload.forms) ? payload.forms : [];
        syncContentLinkSummaries('form',forms.value);
      } finally { formsLoading.value = false; }
    };

    const loadFormSubmissions = async id => {
      const payload = await apiGet('form_submissions', { form_id: String(id) });
      formSubmissions.value = Array.isArray(payload.submissions) ? payload.submissions : [];
    };

    const loadForm = async id => {
      const session = ++formSession;
      if (formAutosaveTimer) window.clearTimeout(formAutosaveTimer);
      formAutosaveTimer = null;
      activeFormSavePromise = null;
      formSaving.value = false;
      currentForm.value = null;
      selectedSubmission.value = null;
      const payload = await apiGet('form', { id: String(id) });
      if (session !== formSession) return;
      currentForm.value = hydrateForm(payload.form);
      formDirty.value = false;
      formSaveState.value = 'saved';
      formLastSavedAt.value = payload.form?.updated_at || null;
      formChangeSerial = 0;
      formTab.value = 'submissions';
      await loadFormSubmissions(id);
    };

    const hydrateDataSet = set => {
      set.mode = set.mode === 'multiple' ? 'multiple' : 'single';
      set.api_enabled = set.api_enabled !== false;
      set.schema = Array.isArray(set.schema) ? set.schema.map((field,index) => ({
        ...field,
        multiple: isUploadType(field?.type) ? field?.multiple === true : false,
        options: Array.isArray(field?.options) ? field.options : [],
        optionsText: Array.isArray(field?.options) ? field.options.join('\n') : '',
        source_data_set_id: field?.type === 'relation' && Number(field?.source_data_set_id) > 0 ? Number(field.source_data_set_id) : null,
        relation_multiple: field?.type === 'relation' && field?.relation_multiple === true,
        display_field: field?.type === 'relation' ? String(field?.display_field || '') : '',
        _uid: `data-field-${Date.now()}-${index}-${Math.random()}`,
      })) : [];
      if (set.mode === 'multiple') {
        const rows = Array.isArray(set.data) ? set.data : [];
        set.data = rows.map(row => {
          const item = row && typeof row === 'object' && !Array.isArray(row) ? {...row} : {};
          item._uid = String(item._uid || makeUid());
          for (const field of set.schema) item[field.key] = normalizeFieldValue(field,item[field.key]);
          return item;
        });
        dataActiveItemUid.value = set.data[0]?._uid || null;
      } else {
        const data = set.data && typeof set.data === 'object' && !Array.isArray(set.data) ? {...set.data} : {};
        for (const field of set.schema) data[field.key] = normalizeFieldValue(field,data[field.key]);
        set.data = data;
        dataActiveItemUid.value = null;
      }
      return set;
    };

    const loadDataSets = async () => {
      dataLoading.value = true;
      try {
        const payload = await apiGet('data_sets');
        dataSets.value = Array.isArray(payload.data_sets) ? payload.data_sets : [];
        syncContentLinkSummaries('data',dataSets.value);
      } finally { dataLoading.value = false; }
    };

    const loadDataSet = async id => {
      const session = ++dataSession;
      if (dataAutosaveTimer) window.clearTimeout(dataAutosaveTimer);
      dataAutosaveTimer = null;
      activeDataSavePromise = null;
      dataSaving.value = false;
      currentDataSet.value = null;
      const payload = await apiGet('data_set',{id:String(id)});
      if (session !== dataSession) return;
      currentDataSet.value = hydrateDataSet(payload.data_set);
      dataDirty.value = false;
      dataSaveState.value = 'saved';
      dataLastSavedAt.value = payload.data_set?.updated_at || null;
      dataChangeSerial = 0;
    };

    const parseRoute = () => {
      const params = new URLSearchParams(window.location.search);
      const docId = Number(params.get('doc') || 0);
      const folderId = Number(params.get('folder') || 0);
      const formId = Number(params.get('form') || 0);
      const dataSetId = Number(params.get('data') || 0);
      const dataRoute = params.get('data-list') === '1';
      const formsRoute = params.get('forms') === '1';
      const files = params.get('files') === '1';
      const aboutRoute = params.get('about') === '1';
      const settingsValue = params.get('settings');
      const settingsRoute = settingsValue === '1';
      const settingsApiRoute = settingsValue === 'api';
      const settingsLanguagesRoute = settingsValue === 'languages';
      const settingsProjectsRoute = settingsValue === 'projects';
      const settingsTeamRoute = settingsValue === 'team';
      const databaseRoute = params.get('database') === '1';
      const projectsRoute = params.get('projects') === '1';
      const usersRoute = params.get('users') === '1';
      route.documentId = null;
      route.folderId = null;
      route.formId = null;
      route.dataSetId = null;
      if (aboutRoute) {
        route.kind = 'about';
      } else if (settingsProjectsRoute || projectsRoute) {
        route.kind = 'settings-projects';
      } else if (settingsTeamRoute || usersRoute) {
        route.kind = 'settings-team';
      } else if (databaseRoute) {
        route.kind = 'database';
      } else if (settingsApiRoute) {
        route.kind = 'settings-api';
      } else if (settingsLanguagesRoute) {
        route.kind = 'settings-languages';
      } else if (dataSetId > 0) {
        route.kind = 'data-set';
        route.dataSetId = dataSetId;
      } else if (dataRoute) {
        route.kind = 'data';
      } else if (formId > 0) {
        route.kind = 'form';
        route.formId = formId;
      } else if (formsRoute) {
        route.kind = 'forms';
      } else if (settingsRoute) {
        route.kind = 'settings';
      } else if (files) {
        route.kind = 'files';
      } else if (docId > 0) {
        route.kind = 'document';
        route.documentId = docId;
      } else {
        route.kind = 'folder';
        route.folderId = folderId > 0 ? folderId : null;
      }
    };

    const routeUrl = ({ folder = null, doc = null, files = false, forms: formsRoute = false, form = null, data: dataSet = null, dataList = false, settings = false, database = false, projects: projectsRoute = false, users: usersRoute = false, about = false } = {}) => {
      if (about) return `${baseUrl}?about=1`;
      if (projectsRoute) return `${baseUrl}?settings=projects`;
      if (usersRoute) return `${baseUrl}?settings=team`;
      if (database) return `${baseUrl}?database=1`;
      if (settings === 'api') return `${baseUrl}?settings=api`;
      if (settings === 'languages') return `${baseUrl}?settings=languages`;
      if (settings === 'projects') return `${baseUrl}?settings=projects`;
      if (settings === 'team') return `${baseUrl}?settings=team`;
      if (settings) return `${baseUrl}?settings=1`;
      if (dataSet) return `${baseUrl}?data=${encodeURIComponent(dataSet)}`;
      if (dataList) return `${baseUrl}?data-list=1`;
      if (form) return `${baseUrl}?form=${encodeURIComponent(form)}`;
      if (formsRoute) return `${baseUrl}?forms=1`;
      if (files) return `${baseUrl}?files=1`;
      if (doc) return `${baseUrl}?doc=${encodeURIComponent(doc)}`;
      if (folder) return `${baseUrl}?folder=${encodeURIComponent(folder)}`;
      return baseUrl;
    };


    const systemThemeQuery = window.matchMedia('(prefers-color-scheme: dark)');

    const resolveTheme = mode => {
      if (mode === 'dark') return 'dark';
      if (mode === 'light') return 'light';
      return systemThemeQuery.matches ? 'dark' : 'light';
    };

    const applyTheme = mode => {
      const normalized = ['system','light','dark'].includes(mode) ? mode : 'system';
      const actual = resolveTheme(normalized);
      document.documentElement.dataset.themeMode = normalized;
      document.documentElement.dataset.theme = actual;
      const color = actual === 'dark' ? '#0f1115' : '#f5f6f8';
      document.querySelector('meta[name="theme-color"]')?.setAttribute('content', color);
    };

    const setThemeMode = mode => {
      mode = ['system','light','dark'].includes(mode) ? mode : 'system';
      themeMode.value = mode;
      try { localStorage.setItem('matercms-theme', mode); } catch {}
      applyTheme(mode);
    };

    const handleSystemThemeChange = () => {
      if (themeMode.value === 'system') applyTheme('system');
    };

    const loadSettings = async () => {
      settingsLoading.value = true;
      try {
        const payload = await apiGet('settings');
        state.i18n = payload.i18n || { enabled: false, default_language: 'ru', languages: ['ru'] };
        state.api_access = payload.api_access || state.api_access;
        state.api_status = payload.api_status || state.api_status;
        state.api_response = payload.api_response || state.api_response;
        languageCatalog.value = payload.languages || {};
        databaseInfo.value = payload.database || databaseInfo.value;
        databaseAvailability.value = payload.database_availability || databaseAvailability.value;
      } finally { settingsLoading.value = false; }
    };


    const resetDatabaseDialog = driver => {
      databaseDialog.driver = driver || databaseInfo.value?.driver || 'sqlite';
      databaseDialog.input_mode = 'url';
      databaseDialog.url = '';
      databaseDialog.host = '127.0.0.1';
      databaseDialog.port = '';
      databaseDialog.database = '';
      databaseDialog.username = '';
      databaseDialog.password = '';
      databaseDialog.sslmode = 'prefer';
    };

    const selectDatabaseDriver = driver => {
      const meta = databaseAvailability.value?.[driver];
      if (!meta?.available) return;
      resetDatabaseDialog(driver);
    };

    const openDatabaseSwitcher = async () => {
      if (!isSystemOwner.value) return;
      if (!Object.keys(databaseAvailability.value || {}).length) {
        try {
          const payload = await apiGet('settings');
          databaseAvailability.value = payload.database_availability || {};
          databaseInfo.value = payload.database || databaseInfo.value;
        } catch (error) {
          notify(error.message || 'Не удалось проверить доступные СУБД.', 'error');
          return;
        }
      }
      resetDatabaseDialog(databaseInfo.value?.driver || 'sqlite');
      modal.value = 'database-switch';
    };

    const changeDatabase = async () => {
      if (!databaseCanSubmit.value || databaseSwitching.value) return;
      const driverLabel = databaseAvailability.value?.[databaseDialog.driver]?.label || databaseDialog.driver;
      const allowed = await askConfirm({
        title: `Перенести MaterCMS в ${driverLabel}?`,
        message: 'MaterCMS проверит соединение, создаст схему в пустой целевой базе, перенесёт все записи и сверит результат. Текущая база останется нетронутой.',
        confirmLabel: 'Начать перенос',
        tone: 'primary',
        icon: 'bi-database-gear',
      });
      if (!allowed) return;

      databaseSwitching.value = true;
      try {
        const payload = await apiPost('switch_database', {
          db_driver: databaseDialog.driver,
          db_input_mode: databaseDialog.input_mode,
          db_url: databaseDialog.url,
          db_host: databaseDialog.host,
          db_port: databaseDialog.port,
          db_name: databaseDialog.database,
          db_user: databaseDialog.username,
          db_password: databaseDialog.password,
          db_sslmode: databaseDialog.sslmode,
        });
        databaseInfo.value = payload.database || databaseInfo.value;
        databaseAvailability.value = payload.database_availability || databaseAvailability.value;
        modal.value = null;
        notify(payload.message || 'База данных изменена.');
      } catch (error) {
        notify(error.message || 'Не удалось сменить базу данных.', 'error');
      } finally {
        databaseSwitching.value = false;
      }
    };

    const saveSettings = async () => {
      settingsSaveQueued.value = true;
      if (settingsSaving.value) return;
      settingsSaving.value = true;
      try {
        while (settingsSaveQueued.value) {
          settingsSaveQueued.value = false;
          const snapshot = JSON.parse(JSON.stringify(state.i18n));
          const payload = await apiPost('save_settings', { i18n: snapshot });
          // Do not overwrite newer local clicks while another save was in flight.
          if (!settingsSaveQueued.value) {
            if (payload.state) applyState(payload.state);
            state.i18n = payload.i18n || state.i18n;
          }
        }
        notify('Настройки сохранены.');
      } catch (error) {
        notify(error.message || 'Не удалось сохранить настройки.', 'error');
      } finally { settingsSaving.value = false; }
    };

    const closeApiSecret = () => {
      apiSecret.value = '';
      if (modal.value === 'api-token') modal.value = null;
    };

    const showApiSecret = token => {
      apiSecret.value = String(token || '');
      if (!apiSecret.value) return;
      modal.value = 'api-token';
    };

    const setApiAccessMode = async mode => {
      if (!can('settings.edit') || busy.value) return;
      mode = mode === 'private' ? 'private' : 'public';
      if (state.api_access?.mode === mode) return;
      if (mode === 'public') {
        const allowed = await askConfirm({
          title: 'Сделать API публичным?',
          message: 'После переключения любой, у кого есть URL проекта, сможет читать контент и отправлять данные в формы без токена. Текущий секретный токен будет отозван.',
          confirmLabel: 'Сделать публичным',
          tone: 'primary',
          icon: 'bi-globe2',
        });
        if (!allowed) return;
      }
      busy.value = true;
      try {
        const payload = await apiPost('set_api_access', { mode });
        if (payload.state) applyState(payload.state);
        state.api_access = payload.api_access || state.api_access;
        notify(payload.message || 'Доступ к API изменён.');
        if (payload.token) showApiSecret(payload.token);
      } catch (error) {
        notify(error.message || 'Не удалось изменить доступ к API.', 'error');
      } finally { busy.value = false; }
    };

    const setApiFullResponse = async enabled => {
      if (!can('settings.edit') || apiResponseSaving.value) return;
      const next = enabled === true;
      if (apiFullResponse.value === next) return;
      apiResponseSaving.value = true;
      try {
        const payload = await apiPost('set_api_response', { full_response: next });
        if (payload.state) applyState(payload.state);
        state.api_response = payload.api_response || state.api_response;
        notify(payload.message || 'Формат ответа API сохранён.');
      } catch (error) {
        state.api_response = { ...state.api_response };
        notify(error.message || 'Не удалось сохранить формат ответа API.', 'error');
      } finally { apiResponseSaving.value = false; }
    };

    const applyFolderApiPreviewPayload = payload => {
      folderApiPreview.value = payload?.tree || null;
      if (payload?.settings) {
        folderApiSettings.cache_ttl = Number(payload.settings.cache_ttl || 0);
        folderApiSettings.cache_enabled = payload.settings.cache_enabled !== false && folderApiSettings.cache_ttl > 0;
      }
      if (payload?.cache_backend) folderApiSettings.cache_backend = payload.cache_backend;
    };

    const loadFolderApiPreview = async () => {
      const folder = currentFolder.value;
      if (!folder?.id) { folderApiPreview.value = null; return; }
      folderApiPreviewLoading.value = true;
      try {
        const payload = await apiGet('folder_api_preview', { id: String(folder.id) });
        applyFolderApiPreviewPayload(payload);
      } catch (error) {
        folderApiPreview.value = null;
        notify(error.message || 'Не удалось собрать пример API папки.', 'error');
      } finally { folderApiPreviewLoading.value = false; }
    };

    const saveFolderApiTreeSettings = async () => {
      const folder=currentFolder.value;
      if(!folder || !can('content.edit') || folderApiSettingsSaving.value) return;
      folderApiSettingsSaving.value=true;
      try{
        const payload=await apiPost('save_folder_api_tree',{
          id:folder.id,
          cache_ttl:Number(folderApiSettings.cache_ttl || 0),
        });
        if(payload.state) applyState(payload.state);
        applyFolderApiPreviewPayload(payload);
        notify(payload.message || 'Кеширование API папки сохранено.');
      }catch(error){
        notify(error.message || 'Не удалось сохранить кеширование API папки.','error');
        await loadFolderApiPreview();
      }finally{folderApiSettingsSaving.value=false;}
    };

    const setProjectApiEnabled = async enabled => {
      if (!can('settings.edit') || busy.value) return;
      busy.value = true;
      try {
        const payload = await apiPost('set_project_api', { enabled: !!enabled });
        if (payload.state) applyState(payload.state);
        state.api_status = payload.api_status || state.api_status;
        notify(payload.message || (enabled ? 'API проекта включён.' : 'API проекта выключен.'));
      } catch (error) {
        notify(error.message || 'Не удалось изменить состояние API проекта.', 'error');
      } finally { busy.value = false; }
    };

    const setFolderApiEnabled = async enabled => {
      const folder = currentFolder.value;
      if (!folder || !can('content.edit') || busy.value) return;
      busy.value = true;
      try {
        const payload = await apiPost('set_folder_api', { id: folder.id, enabled: !!enabled });
        if (payload.state) applyState(payload.state);
        notify(payload.message || (enabled ? 'API папки включён.' : 'API папки выключен.'));
        await loadFolderApiPreview();
      } catch (error) {
        notify(error.message || 'Не удалось изменить API папки.', 'error');
      } finally { busy.value = false; }
    };

    const setDocumentApiEnabled = async enabled => {
      const doc = currentDocument.value;
      if (!doc || !can('content.edit') || busy.value) return;
      if (!(await flushAutosave())) return;
      busy.value = true;
      try {
        const payload = await apiPost('set_document_api', { id: doc.id, enabled: !!enabled });
        if (payload.state) applyState(payload.state);
        if (payload.document) mergeDocumentBackgroundState(payload);
        currentDocument.value.api_enabled = !!enabled;
        notify(payload.message || (enabled ? 'API раздела включён.' : 'API раздела выключен.'));
      } catch (error) {
        notify(error.message || 'Не удалось изменить API раздела.', 'error');
      } finally { busy.value = false; }
    };

    const setFormApiEnabled = async enabled => {
      const activeForm = currentForm.value;
      if (!activeForm || !can('forms.edit') || busy.value) return;
      if (!(await flushFormAutosave())) return;
      busy.value = true;
      try {
        const payload = await apiPost('set_form_api', { id: activeForm.id, enabled: !!enabled });
        if (payload.form) currentForm.value = hydrateForm(payload.form);
        if (Array.isArray(payload.forms)) { forms.value = payload.forms; syncContentLinkSummaries('form',payload.forms); }
        notify(payload.message || (enabled ? 'API формы включён.' : 'API формы выключен.'));
      } catch (error) {
        notify(error.message || 'Не удалось изменить API формы.', 'error');
      } finally { busy.value = false; }
    };

    const regenerateApiToken = async () => {
      if (!can('settings.edit') || busy.value) return;
      const allowed = await askConfirm({
        title: 'Перевыпустить секретный токен?',
        message: 'Старый токен перестанет работать сразу. Обновите секрет во всех серверных приложениях, которые используют этот проект.',
        confirmLabel: 'Перевыпустить',
        tone: 'primary',
        icon: 'bi-arrow-repeat',
      });
      if (!allowed) return;
      busy.value = true;
      try {
        const payload = await apiPost('regenerate_api_token');
        if (payload.state) applyState(payload.state);
        state.api_access = payload.api_access || state.api_access;
        notify(payload.message || 'Новый токен создан.');
        showApiSecret(payload.token);
      } catch (error) {
        notify(error.message || 'Не удалось перевыпустить токен.', 'error');
      } finally { busy.value = false; }
    };

    const languageSelected = code => Array.isArray(state.i18n?.languages) && state.i18n.languages.includes(code);
    const toggleLanguage = async code => {
      const current = Array.isArray(state.i18n.languages) ? [...state.i18n.languages] : [];
      if (current.includes(code)) {
        if (current.length === 1) { notify('Нужно оставить хотя бы один язык.', 'error'); return; }
        state.i18n.languages = current.filter(item => item !== code);
        if (state.i18n.default_language === code) state.i18n.default_language = state.i18n.languages[0];
      } else {
        state.i18n.languages = [...current, code];
      }
      await saveSettings();
    };

    const setDefaultLanguage = async code => {
      if (!languageSelected(code)) return;
      state.i18n.default_language = code;
      await saveSettings();
    };

    const setI18nEnabled = async value => {
      state.i18n.enabled = !!value;
      await saveSettings();
    };

    const setSubmissionView = mode => {
      submissionViewMode.value = mode === 'grid' ? 'grid' : 'list';
      localStorage.setItem('feather-submission-view', submissionViewMode.value);
    };

    const flushAutosave = async () => {
      clearAutosaveTimer();
      let attempts = 0;
      while (dirty.value && attempts++ < 3) {
        await saveDocument({ silent: true });
        if (saveState.value === 'error') break;
      }
      return !dirty.value;
    };

    const canLeaveDocument = async () => {
      if (!dirty.value && !saving.value) return true;
      const saved = await flushAutosave();
      if (saved) return true;
      return askConfirm({
        title: 'Покинуть без сохранения?',
        message: 'MaterCMS не смог завершить автосохранение. Последние изменения могут потеряться.',
        confirmLabel: 'Покинуть',
        icon: 'bi-cloud-slash',
      });
    };

    const loadProjects = async () => {
      projectLoading.value = true;
      try {
        const payload = await apiGet('projects');
        projects.value = Array.isArray(payload.projects) ? payload.projects : [];
        state.projects = projects.value;
      } finally { projectLoading.value = false; }
    };

    const loadProjectMembers = async () => {
      if (!can('members.view')) { projectMembers.value = []; return; }
      const payload = await apiGet('project_members');
      projectMembers.value = Array.isArray(payload.members) ? payload.members : [];
      availableUsers.value = Array.isArray(payload.available_users) ? payload.available_users : [];
    };

    const loadUsers = async () => {
      usersLoading.value = true;
      try {
        const payload = await apiGet('users');
        users.value = Array.isArray(payload.users) ? payload.users : [];
      } finally { usersLoading.value = false; }
    };

    const loadProductAbout = async (force = false) => {
      if (productAbout.value && !force) return productAbout.value;
      productAboutLoading.value = true;
      try {
        const payload = await apiGet('product_about');
        productAbout.value = payload;
        return payload;
      } finally {
        productAboutLoading.value = false;
      }
    };

    const filteredProductReleases = computed(() => {
      const releases = Array.isArray(productAbout.value?.releases) ? productAbout.value.releases : [];
      const query = aboutReleaseQuery.value.trim().toLowerCase();
      if (!query) return releases;
      return releases.filter(release => {
        const haystack = [release.version,release.title,...(release.sections || []).flatMap(section => [section.title,...(section.items || [])])].join(' ').toLowerCase();
        return haystack.includes(query);
      });
    });

    const openProductDocument = async key => {
      const requestId = ++aboutDocumentRequestSeq;
      aboutDocument.value = null;
      aboutDocumentLoading.value = true;
      try {
        const payload = await apiGet('product_document', { document:key });
        if (requestId !== aboutDocumentRequestSeq) return;
        aboutDocument.value = payload.document || null;
      } catch (error) {
        if (requestId !== aboutDocumentRequestSeq) return;
        notify(error.message || 'Не удалось открыть документ.', 'error');
      } finally {
        if (requestId === aboutDocumentRequestSeq) aboutDocumentLoading.value = false;
      }
    };

    const closeProductDocument = () => {
      aboutDocumentRequestSeq += 1;
      aboutDocumentLoading.value = false;
      aboutDocument.value = null;
    };

    const escapeMarkdownHtml = value => String(value ?? '')
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');

    const renderMarkdownInline = value => {
      let html = escapeMarkdownHtml(value);
      html = html.replace(/`([^`]+)`/g,'<code>$1</code>');
      html = html.replace(/\*\*([^*]+)\*\*/g,'<strong>$1</strong>');
      html = html.replace(/\[([^\]]+)\]\(([^)]+)\)/g,(_m,label,url) => {
        const safeUrl = /^(?:https?:|mailto:|#|\.\.?\/)/i.test(url) ? url : '#';
        return `<a href="${escapeMarkdownHtml(safeUrl)}" target="_blank" rel="noopener noreferrer">${label}</a>`;
      });
      return html;
    };

    const renderMarkdown = markdown => {
      const lines = String(markdown || '').split(/\r?\n/);
      const out = [];
      let inCode = false;
      let code = [];
      let list = null;
      const closeList = () => { if (list) { out.push(`</${list}>`); list = null; } };
      for (const raw of lines) {
        const line = raw || '';
        if (/^```/.test(line.trim())) {
          closeList();
          if (inCode) { out.push(`<pre><code>${escapeMarkdownHtml(code.join('\n'))}</code></pre>`); code=[]; inCode=false; }
          else inCode=true;
          continue;
        }
        if (inCode) { code.push(line); continue; }
        if (/^\s*<(?:\/?picture|source|img|\/?p\b)/i.test(line)) continue;
        if (!line.trim()) { closeList(); continue; }
        const heading = line.match(/^(#{1,6})\s+(.+)$/);
        if (heading) { closeList(); const level=Math.min(6,heading[1].length+1); out.push(`<h${level}>${renderMarkdownInline(heading[2])}</h${level}>`); continue; }
        const bullet = line.match(/^\s*[-*]\s+(.+)$/);
        if (bullet) { if(list!=='ul'){closeList();list='ul';out.push('<ul>');} out.push(`<li>${renderMarkdownInline(bullet[1])}</li>`); continue; }
        const ordered = line.match(/^\s*\d+[.)]\s+(.+)$/);
        if (ordered) { if(list!=='ol'){closeList();list='ol';out.push('<ol>');} out.push(`<li>${renderMarkdownInline(ordered[1])}</li>`); continue; }
        closeList();
        if (/^---+$/.test(line.trim())) { out.push('<hr>'); continue; }
        out.push(`<p>${renderMarkdownInline(line)}</p>`);
      }
      if (inCode) out.push(`<pre><code>${escapeMarkdownHtml(code.join('\n'))}</code></pre>`);
      closeList();
      return out.join('');
    };

    const syncRoute = async () => {
      parseRoute();
      search.value = '';
      contextMenu.value = null;
      contentSelection.value = []; contentSelectionAnchor.value = null;
      if (route.kind === 'document') {
        try {
          await loadDocument(route.documentId);
        } catch (error) {
          notify(error.message || 'Не удалось открыть раздел.', 'error');
          history.replaceState({}, '', baseUrl);
          parseRoute();
        }
      } else if (route.kind === 'data-set') {
        currentDocument.value = null;
        currentForm.value = null;
        dirty.value = false;
        formDirty.value = false;
        cleanupPreviews();
        try {
          await loadDataSets();
          await loadDataSet(route.dataSetId);
          await preloadDataRelations();
        } catch (error) {
          notify(error.message || 'Не удалось открыть данные.', 'error');
          history.replaceState({}, '', `${baseUrl}?data-list=1`);
          parseRoute();
          await loadDataSets();
        }
      } else if (route.kind === 'form') {
        currentDocument.value = null;
        dirty.value = false;
        cleanupPreviews();
        await loadForms();
        try {
          await loadForm(route.formId);
        } catch (error) {
          notify(error.message || 'Не удалось открыть форму.', 'error');
          history.replaceState({}, '', `${baseUrl}?forms=1`);
          parseRoute();
          await loadForms();
        }
      } else {
        currentDocument.value = null;
        currentForm.value = null;
        currentDataSet.value = null;
        selectedSubmission.value = null;
        dirty.value = false;
        formDirty.value = false;
        dataDirty.value = false;
        cleanupPreviews();
        cleanupDataPreviews();
        if (route.kind === 'about') await loadProductAbout();
        if (route.kind === 'files') await loadFiles();
        if (route.kind === 'data') await loadDataSets();
        if (route.kind === 'forms') await loadForms();
        if (['settings','settings-api','settings-languages','settings-projects','settings-team','database'].includes(route.kind)) await loadSettings();
        if (route.kind === 'settings-projects') {
          await loadProjects();
          if (can('members.view')) await loadProjectMembers();
          else { projectMembers.value = []; availableUsers.value = []; }
        }
        if (route.kind === 'settings-team' && isSystemOwner.value) await loadUsers();
      }
    };

    const flushFormAutosave = async () => {
      if (formAutosaveTimer) window.clearTimeout(formAutosaveTimer);
      formAutosaveTimer = null;
      let attempts = 0;
      while ((formDirty.value || formSaving.value) && attempts++ < 4) {
        const ok = await saveForm({ silent: true });
        if (!ok && formSaveState.value === 'error') return false;
        if (formSaving.value) await new Promise(resolve => window.setTimeout(resolve, 80));
      }
      return !formDirty.value && !formSaving.value;
    };

    const skeletonKindFromRouteKind = kind => {
      if (['document','data-set','form'].includes(kind)) return 'editor';
      if (['settings','settings-api','settings-languages','database'].includes(kind)) return 'settings';
      if (['settings-projects','settings-team','about'].includes(kind)) return 'management';
      return 'explorer';
    };

    const routeKindFromTarget = target => {
      if (!target) {
        const params = new URLSearchParams(window.location.search);
        const settings = params.get('settings');
        if (params.get('about') === '1') return 'about';
        if (settings === 'projects' || params.get('projects') === '1') return 'settings-projects';
        if (settings === 'team' || params.get('users') === '1') return 'settings-team';
        if (params.get('database') === '1') return 'database';
        if (settings === 'api') return 'settings-api';
        if (settings === 'languages') return 'settings-languages';
        if (Number(params.get('data') || 0) > 0) return 'data-set';
        if (params.get('data-list') === '1') return 'data';
        if (Number(params.get('form') || 0) > 0) return 'form';
        if (params.get('forms') === '1') return 'forms';
        if (settings === '1') return 'settings';
        if (params.get('files') === '1') return 'files';
        if (Number(params.get('doc') || 0) > 0) return 'document';
        return 'folder';
      }
      if (target.about) return 'about';
      if (target.projects || target.settings === 'projects') return 'settings-projects';
      if (target.users || target.settings === 'team') return 'settings-team';
      if (target.database) return 'database';
      if (target.settings === 'api') return 'settings-api';
      if (target.settings === 'languages') return 'settings-languages';
      if (target.data) return 'data-set';
      if (target.dataList) return 'data';
      if (target.form) return 'form';
      if (target.forms) return 'forms';
      if (target.settings) return 'settings';
      if (target.files) return 'files';
      if (target.doc) return 'document';
      return 'folder';
    };

    const beginRouteTransition = target => {
      routeSkeletonKind.value = skeletonKindFromRouteKind(routeKindFromTarget(target));
      routeTransitionStartedAt = performance.now();
      pageNavigating.value = true;
      document.documentElement.classList.add('matercms-route-loading');
    };

    const finishRouteTransition = async () => {
      // Navigation starts immediately. A very small minimum visibility window keeps
      // the skeleton perceptible without delaying the request itself. Slow routes
      // naturally remain visible for their real load duration.
      const minimumVisibleMs = 120;
      const elapsed = performance.now() - routeTransitionStartedAt;
      if (elapsed < minimumVisibleMs) {
        await new Promise(resolve => window.setTimeout(resolve, minimumVisibleMs - elapsed));
      }
      routeViewKey.value += 1;
      await nextTick();
      await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
      pageNavigating.value = false;
      document.documentElement.classList.remove('matercms-route-loading');
    };

    const navigate = async target => {
      if (route.kind === 'folder') clearContentSelection();
      if (route.kind === 'document' && !(await canLeaveDocument())) return false;
      if (route.kind === 'data-set' && !(await flushDataAutosave())) {
        const allowed = await askConfirm({ title:'Покинуть без сохранения?', message:'Не удалось сохранить последние изменения данных.', confirmLabel:'Покинуть', icon:'bi-cloud-slash' });
        if (!allowed) return false;
      }
      if (route.kind === 'form' && !(await flushFormAutosave())) {
        const allowed = await askConfirm({ title:'Покинуть без сохранения?', message:'Не удалось сохранить последние изменения формы.', confirmLabel:'Покинуть', icon:'bi-cloud-slash' });
        if (!allowed) return false;
      }
      resetGlobalSearch();
      beginRouteTransition(target);
      try {
        history.pushState({}, '', routeUrl(target));
        await syncRoute();
        window.scrollTo({ top: 0, behavior: 'auto' });
        await finishRouteTransition();
      } catch (error) {
        pageNavigating.value = false;
        document.documentElement.classList.remove('matercms-route-loading');
        throw error;
      }
      return true;
    };

    const goRoot = () => navigate({});
    const openFolder = id => navigate({ folder: id });
    const openDocument = id => navigate({ doc: id });
    const openFiles = () => { drawer.value = null; return navigate({ files: true }); };
    const openData = () => { drawer.value = null; return navigate({ dataList: true }); };
    const openDataSet = id => { drawer.value = null; return navigate({ data: id }); };
    const openForms = () => { drawer.value = null; return navigate({ forms: true }); };
    const openSettings = () => { drawer.value = null; return navigate({ settings: true }); };
    const openAbout = () => { drawer.value = null; return navigate({ about: true }); };
    const openApiSettings = () => { drawer.value = null; return navigate({ settings: 'api' }); };
    const openLanguageSettings = () => { drawer.value = null; return navigate({ settings: 'languages' }); };
    const openTeamSettings = () => { drawer.value = null; return navigate({ settings: 'team' }); };
    const openProjectSettings = () => { drawer.value = null; return navigate({ settings: 'projects' }); };
    const openDatabaseSettings = () => { drawer.value = null; return navigate({ database: true }); };
    const openProjects = () => openProjectSettings();
    const openUsers = () => openTeamSettings();
    const openForm = id => navigate({ form: id });

    const openGlobalSearchResult = async item => {
      if (!item) return;
      resetGlobalSearch();
      if (item.type === 'folder') return openFolder(item.id);
      if (item.type === 'document') return openDocument(item.id);
      if (item.type === 'data') return openDataSet(item.id);
      if (item.type === 'form') return openForm(item.id);
      if (item.type === 'project') {
        await openProjects();
        return;
      }
      if (item.type === 'user') {
        await openUsers();
        return;
      }
      if (item.type === 'file') {
        await openFiles();
        const targetName = item.file?.name || item.key;
        const target = mediaFiles.value.find(file => file.name === targetName) || item.file;
        if (target) previewFile(target);
        return;
      }
    };

    const showContextMenu = (event, payload = {}) => {
      if (!event) return;
      event.preventDefault?.();
      event.stopPropagation?.();
      userMenu.value = false;
      createMenu.value = null;
      const x = Number(event.clientX ?? 16);
      const y = Number(event.clientY ?? 16);
      contextMenu.value = { ...payload, x, y };
      nextTick(() => {
        const menu = document.querySelector('.context-menu');
        if (!menu || !contextMenu.value) return;
        const rect = menu.getBoundingClientRect();
        const pad = 10;
        contextMenu.value.x = Math.max(pad, Math.min(x, window.innerWidth - rect.width - pad));
        contextMenu.value.y = Math.max(pad, Math.min(y, window.innerHeight - rect.height - pad));
      });
    };

    const openPageContext = event => {
      const target = event?.target;
      if (!target) return;
      // Keep the browser's native text/edit menu where it is actually useful.
      const selectedText = String(window.getSelection?.()?.toString?.() || '').trim();
      if (selectedText || target.closest('input,textarea,select,[contenteditable="true"],a[href],pre,code')) return;
      if (target.closest('.context-menu,.modal-card,.drawer')) return;
      if (route.kind === 'folder') clearContentSelection();
      showContextMenu(event, { type: 'page', item: null });
    };

    const openKeyboardContentMenu = () => {
      if (route.kind !== 'folder' || !contentSelection.value.length) return false;
      const selected = contentSelection.value[contentSelection.value.length - 1];
      const item = contentItemBySelection(selected);
      if (!item) return false;
      const node = document.querySelector(`[data-content-key="${contentKey(selected.type,selected.id)}"]`);
      const rect = node?.getBoundingClientRect?.();
      showContextMenu({
        preventDefault(){}, stopPropagation(){},
        clientX: rect ? Math.min(rect.right - 18, rect.left + 80) : 24,
        clientY: rect ? Math.min(rect.bottom - 12, rect.top + 38) : 80,
      }, { type:selected.type, item });
      return true;
    };

    const openObjectContext = (event, type, item, extra = {}) => {
      if (['folder','document','resource-link'].includes(type) && !isContentSelected(type,item)) selectContentItem(type, item);
      showContextMenu(event, { type, item, ...extra });
    };
    const openRecordContext = (event, kind, item, index) => openObjectContext(event, kind === 'data' ? 'record-data' : 'record-document', item, { index });
    const openSubmissionContext = (event, submission) => openObjectContext(event, 'submission', submission);

    const selectContextRecord = menu => {
      if (!menu?.item?._uid) return false;
      const kind = menu.type === 'record-data' ? 'data' : 'document';
      if (kind === 'data') dataActiveItemUid.value = menu.item._uid;
      else activeItemUid.value = menu.item._uid;
      recordDialog.kind = kind;
      recordDialog.uid = menu.item._uid;
      recordDialog.index = Number(menu.index || 0);
      recordDialog.mode = 'menu';
      return true;
    };

    const persistContentClipboard = value => {
      contentClipboard.value = value;
      try {
        if (value) sessionStorage.setItem('feather-content-clipboard', JSON.stringify(value));
        else sessionStorage.removeItem('feather-content-clipboard');
      } catch (_) {}
    };

    const contentItemBySelection = selection => {
      if (!selection) return null;
      if (Number(selection.project_id || 0) > 0 && Number(selection.project_id) !== Number(state.current_project?.id || 0)) return null;
      const source = selection.type === 'folder'
        ? state.folders
        : (selection.type === 'document' ? state.documents : (state.content_links || []));
      const item = source.find(entry => Number(entry.id) === Number(selection.id));
      return item ? { ...item, type: selection.type } : null;
    };

    const selectionWithoutNested = selections => {
      const list = (Array.isArray(selections) ? selections : []).map(entry => contentItemBySelection(entry) || entry).filter(Boolean);
      const selectedFolderIds = new Set(list.filter(item => item.type === 'folder').map(item => Number(item.id)));
      const folderById = new Map(state.folders.map(folder => [Number(folder.id),folder]));
      const insideSelectedFolder = item => {
        let parentId = item.type === 'folder' ? item.parent_id : item.folder_id;
        const guard = new Set();
        while (parentId && !guard.has(Number(parentId))) {
          const id = Number(parentId); guard.add(id);
          if (selectedFolderIds.has(id)) return true;
          parentId = folderById.get(id)?.parent_id ?? null;
        }
        return false;
      };
      return list.filter(item => !insideSelectedFolder(item));
    };

    const clipboardEntries = (type = null, item = null) => {
      let selected = [];
      if (type && item && isContentSelected(type,item) && contentSelection.value.length > 1) selected = selectedContentItems.value;
      else if (type && item) selected = [{...item,type}];
      else selected = selectedContentItems.value;
      return selectionWithoutNested(selected).map(entry => ({
        type:entry.type,
        id:Number(entry.id),
        name:String(entry.name || (entry.type === 'folder' ? 'Папка' : (entry.type === 'document' ? 'Раздел' : 'Связанный ресурс'))),
        project_id:Number(state.current_project?.id || 0),
      }));
    };

    const setContentClipboard = (type = null, item = null) => {
      const items = clipboardEntries(type,item);
      if (!items.length) return false;
      const next = { operation:'copy', items };
      persistContentClipboard(next);
      replaceContentSelection(items);
      notify(items.length === 1 ? `Скопировано: «${items[0].name}».` : `Скопировано объектов: ${items.length}.`);
      return true;
    };

    const pushExplorerHistory = operation => {
      if (!operation) return;
      explorerHistory.value = [...explorerHistory.value.slice(-29), operation];
      explorerRedo.value = [];
    };

    const apiDeleteContentPermanently = async item => {
      if (item.type === 'resource-link') return apiPost('delete_content_link',{id:Number(item.id)});
      const action = item.type === 'folder' ? 'delete_folder' : 'delete_document';
      return apiPost(action,{id:Number(item.id)});
    };

    const pasteEntries = async (entries, destinationFolderId = route.folderId, options = {}) => {
      const items = selectionWithoutNested(entries);
      if (!items.length || clipboardBusy.value) return false;
      if (!can('content.edit')) { notify('Нет прав на изменение содержимого этой папки.','error'); return false; }
      clipboardBusy.value = true;
      const results=[]; const failures=[];
      try {
        for (const entry of items) {
          try {
            const payload = await apiPost('paste_content', {
              item_type:entry.type,
              item_id:Number(entry.id),
              source_project_id:Number(entry.project_id || state.current_project?.id || 0),
              destination_folder_id:destinationFolderId ?? null,
            });
            if (payload.state) applyState(payload.state);
            if (payload.result) results.push({...payload.result,project_id:Number(state.current_project?.id || 0),noop:payload.noop===true});
          } catch (error) { failures.push({entry,error}); }
        }
        if (results.length) replaceContentSelection(results);
        const changedResults=results.filter(item=>!item.noop);
        if (options.history !== false && changedResults.length) {
          pushExplorerHistory({kind:'copy',source:items.map(item=>({...item})),destination:destinationFolderId??null,created:changedResults.map(({noop,...item})=>({...item}))});
        }
        if (failures.length) notify(`Готово: ${results.length}. Не удалось: ${failures.length}. ${failures[0].error?.message || ''}`,'error');
        else if(results.length && !changedResults.length) notify('Этот связанный ресурс уже находится в папке назначения.');
        else notify(changedResults.length === 1 ? 'Копия создана.' : `Скопировано объектов: ${changedResults.length}.`);
        return results.length > 0;
      } finally { clipboardBusy.value = false; }
    };

    const pasteContent = async (destinationFolderId = route.folderId) => {
      const clip = contentClipboard.value;
      if (!clip?.items?.length) return false;
      return pasteEntries(clip.items,destinationFolderId);
    };

    const duplicateSelection = async () => {
      const items = clipboardEntries();
      if (!items.length) return;
      await pasteEntries(items,route.folderId);
    };

    const clearContentSelection = () => { contentSelection.value = []; contentSelectionAnchor.value = null; };
    const selectAllContent = () => {
      replaceContentSelection(contentOrderedItems.value);
      contentSelectionAnchor.value = contentOrderedItems.value.length ? contentKey(contentOrderedItems.value[0].type,contentOrderedItems.value[0].id) : null;
    };
    const invertContentSelection = () => {
      const selected = new Set(contentSelection.value.map(item => contentKey(item.type,item.id)));
      replaceContentSelection(contentOrderedItems.value.filter(item => !selected.has(contentKey(item.type,item.id))));
      contentSelectionAnchor.value = contentSelection.value.length ? contentKey(contentSelection.value[contentSelection.value.length - 1].type,contentSelection.value[contentSelection.value.length - 1].id) : null;
    };

    const setContentSort = (by, direction = null) => {
      const normalized = ['name','type','updated'].includes(by) ? by : 'name';
      const nextDirection = direction || (contentSort.value.by === normalized && contentSort.value.direction === 'asc' ? 'desc' : 'asc');
      contentSort.value = {by:normalized,direction:nextDirection === 'desc' ? 'desc' : 'asc'};
      contentSortMenu.value = false;
      try { localStorage.setItem('feather-content-sort',JSON.stringify(contentSort.value)); } catch (_) {}
    };

    const selectedSingleContent = computed(() => selectedContentItems.value.length === 1 ? selectedContentItems.value[0] : null);
    const contentPathForItem = item => {
      const folderId = item?.type === 'folder' ? item?.parent_id : item?.folder_id;
      const trail = folderTrail(folderId || null).map(folder=>folder.name);
      return ['Мой контент',...trail,String(item?.name || '')].join(' / ');
    };
    const openContentProperties = item => {
      const target = item || selectedSingleContent.value;
      if (!target) return;
      contentProperties.value = {
        ...target,
        path:contentPathForItem(target),
        typeLabel:target.type === 'folder'
          ? 'Папка'
          : (target.type === 'document' ? 'Раздел' : (target.resource_type === 'form' ? 'Связанная форма' : 'Связанные данные')),
        details:target.type === 'folder'
          ? `${childCount(target.id)} ${plural(childCount(target.id),'элемент','элемента','элементов')}`
          : (target.type === 'resource-link'
              ? 'Ссылка на исходный ресурс · источник истины не дублируется'
              : (target.mode === 'multiple' ? `${Number(target.item_count || 0)} ${plural(Number(target.item_count || 0),'запись','записи','записей')}` : 'Одиночный раздел')),
      };
      modal.value = 'content-properties';
    };

    const openSelectedContent = () => {
      const item = selectedSingleContent.value;
      if (!item) return;
      if (item.type === 'folder') return openFolder(item.id);
      if (item.type === 'document') return openDocument(item.id);
      return openLinkedResource(item);
    };
    const renameSelectedContent = () => {
      const item=selectedSingleContent.value;
      if(!item || !can('content.edit') || item.type === 'resource-link') return;
      dialog.type=item.type; dialog.target=item; dialog.name=item.name; modal.value='rename';
      nextTick(()=>document.querySelector('.modal-card input')?.select());
    };

    const openSelectedInNewTab = item => {
      const target=item || selectedSingleContent.value;
      if(!target)return;
      const href=target.type==='folder'
        ? routeUrl({folder:target.id})
        : (target.type==='document'
            ? routeUrl({doc:target.id})
            : (target.resource_type==='form' ? routeUrl({form:target.resource_id}) : routeUrl({data:target.resource_id})));
      window.open(href,'_blank','noopener,noreferrer');
    };

    const selectAdjacentContent = (delta, extend = false) => {
      const items=contentOrderedItems.value;if(!items.length)return;
      const current=contentSelection.value.length ? contentSelection.value[contentSelection.value.length-1] : null;
      let index=current?items.findIndex(item=>contentKey(item.type,item.id)===contentKey(current.type,current.id)):-1;
      index=Math.max(0,Math.min(items.length-1,index<0?(delta>0?0:items.length-1):index+delta));
      const target=items[index];
      if(extend){
        const synthetic={shiftKey:true,ctrlKey:false,metaKey:false};
        selectContentItem(target.type,target,synthetic);
      }else selectContentItem(target.type,target);
      nextTick(()=>document.querySelector(`[data-content-key="${contentKey(target.type,target.id)}"]`)?.scrollIntoView({block:'nearest'}));
    };

    const openParentFolder = () => {
      if(route.kind!=='folder')return;
      const parent=currentFolder.value?.parent_id ?? null;
      openFolder(parent);
    };
    const goBack = () => window.history.back();
    const goForward = () => window.history.forward();

    const loadTrash = async () => {
      trashLoading.value=true;
      try{const payload=await apiPost('trash_list',{});trashItems.value=Array.isArray(payload.items)?payload.items:[];trashSelection.value=[];}
      catch(error){notify(error.message || 'Не удалось открыть корзину.','error');}
      finally{trashLoading.value=false;}
    };
    const openTrash = async () => { drawer.value='trash'; await loadTrash(); };
    const trashSelectionCount = computed(() => trashSelection.value.length);
    const allTrashSelected = computed(() => trashItems.value.length > 0 && trashSelection.value.length === trashItems.value.length);
    const isTrashSelected = item => trashSelection.value.includes(Number(item?.id));
    const toggleTrashSelection = item => {
      const id=Number(item?.id || 0);if(!id)return;
      trashSelection.value=isTrashSelected(item)?trashSelection.value.filter(x=>x!==id):[...trashSelection.value,id];
    };
    const toggleAllTrash = () => {
      trashSelection.value=allTrashSelected.value?[]:trashItems.value.map(item=>Number(item.id));
    };
    const restoreTrash = async ids => {
      const selected=Array.isArray(ids)&&ids.length?ids:trashSelection.value;if(!selected.length)return;
      try{const payload=await apiPost('restore_trash',{trash_ids:selected});if(payload.state)applyState(payload.state);notify(payload.message || 'Восстановлено.');await loadTrash();}
      catch(error){notify(error.message || 'Не удалось восстановить.','error');}
    };
    const deleteTrashForever = async ids => {
      const selected=Array.isArray(ids)&&ids.length?ids:trashSelection.value;if(!selected.length)return;
      const allowed=await askConfirm({title:'Удалить навсегда?',message:`Выбранные объекты (${selected.length}) нельзя будет восстановить.`,confirmLabel:'Удалить навсегда',icon:'bi-trash3'});if(!allowed)return;
      try{const payload=await apiPost('delete_trash',{trash_ids:selected});if(payload.state)applyState(payload.state);notify(payload.message);await loadTrash();}
      catch(error){notify(error.message || 'Не удалось удалить.','error');}
    };
    const emptyTrash = async () => {
      if(!trashItems.value.length)return;
      const allowed=await askConfirm({title:'Очистить корзину?',message:'Все объекты в корзине будут удалены навсегда.',confirmLabel:'Очистить корзину',icon:'bi-trash3'});if(!allowed)return;
      try{const payload=await apiPost('empty_trash',{});if(payload.state)applyState(payload.state);notify(payload.message);await loadTrash();}
      catch(error){notify(error.message || 'Не удалось очистить корзину.','error');}
    };

    const trashSelectionItems = async (permanent=false) => {
      const items=selectionWithoutNested(selectedContentItems.value);if(!items.length)return;
      const links=items.filter(item=>item.type==='resource-link');
      const nativeItems=items.filter(item=>item.type!=='resource-link');
      const onlyLinks=links.length===items.length;
      const title=permanent
        ? (onlyLinks ? 'Убрать связи?' : 'Удалить навсегда?')
        : (onlyLinks ? (links.length===1 ? 'Убрать из папки?' : 'Убрать связи из папки?') : 'Удалить выбранное?');
      const message=onlyLinks
        ? 'Удаляется только связь в «Мой контент». Исходные Данные и Формы останутся без изменений.'
        : (links.length
            ? 'Папки и разделы будут перемещены в корзину, а связанные Данные/Формы будут только убраны из этой папки. Исходные ресурсы не удаляются.'
            : (permanent ? 'Эти объекты нельзя будет восстановить.' : `${items.length} ${plural(items.length,'объект','объекта','объектов')} можно будет восстановить из корзины.`));
      const allowed=await askConfirm({
        title,message,
        confirmLabel:onlyLinks?'Убрать связь':(permanent?'Удалить навсегда':'Удалить'),
        icon:onlyLinks?'bi-link-45deg':(permanent?'bi-trash3':'bi-recycle')
      });if(!allowed)return;
      const trashIds=[];const deleted=[];const removedLinks=[];
      try{
        for(const item of items){
          if(item.type==='resource-link'){
            const payload=await apiPost('delete_content_link',{id:Number(item.id)});
            if(payload.state)applyState(payload.state);
            if(payload.link)removedLinks.push(payload.link);
            deleted.push(item);
          }else if(permanent){
            const payload=await apiDeleteContentPermanently(item);if(payload.state)applyState(payload.state);deleted.push(item);
          }else{
            const payload=await apiPost('trash_content',{item_type:item.type,item_id:Number(item.id)});
            if(payload.state)applyState(payload.state);
            if(payload.trash_id)trashIds.push(Number(payload.trash_id));
            deleted.push(item);
          }
        }
        clearContentSelection();
        if(!permanent){
          if(trashIds.length && removedLinks.length)pushExplorerHistory({kind:'delete-mixed',trashIds,links:removedLinks});
          else if(trashIds.length)pushExplorerHistory({kind:'trash',trashIds,items:nativeItems.map(item=>({type:item.type,id:Number(item.id),name:item.name}))});
          else if(removedLinks.length)pushExplorerHistory({kind:'unlink',links:removedLinks});
        }
        if(onlyLinks) notify(`Убрано связей из папки: ${removedLinks.length || links.length}.`);
        else if(links.length) notify(`Готово: ${nativeItems.length} в корзину, связей убрано: ${links.length}.`);
        else notify(permanent?`Удалено навсегда: ${deleted.length}.`:`Перемещено в корзину: ${deleted.length}.`);
      }catch(error){notify(error.message || 'Операция не выполнена.','error');await loadState();}
    };

    const undoExplorer = async () => {
      const op=explorerHistory.value[explorerHistory.value.length-1];if(!op||clipboardBusy.value)return;
      explorerHistory.value=explorerHistory.value.slice(0,-1);
      try{
        if(op.kind==='rename'){
          const payload=await apiPost(op.type==='folder'?'rename_folder':'rename_document',{id:op.id,name:op.from});if(payload.state)applyState(payload.state);
        }else if(op.kind==='copy'){
          for(const created of [...op.created].reverse()) { const payload=await apiDeleteContentPermanently(created); if(payload.state)applyState(payload.state); }
          clearContentSelection();
        }else if(op.kind==='trash'){
          const payload=await apiPost('restore_trash',{trash_ids:op.trashIds});if(payload.state)applyState(payload.state);op.restored=payload.restored||[];
        }else if(op.kind==='unlink'){
          const payload=await apiPost('restore_content_links',{links:op.links});if(payload.state)applyState(payload.state);op.links=payload.links||op.links;
        }else if(op.kind==='delete-mixed'){
          if(op.trashIds?.length){const payload=await apiPost('restore_trash',{trash_ids:op.trashIds});if(payload.state)applyState(payload.state);op.restored=payload.restored||[];}
          if(op.links?.length){const payload=await apiPost('restore_content_links',{links:op.links});if(payload.state)applyState(payload.state);op.links=payload.links||op.links;}
        }
        explorerRedo.value=[...explorerRedo.value,op];notify('Действие отменено.');
      }catch(error){explorerHistory.value=[...explorerHistory.value,op];notify(error.message || 'Не удалось отменить действие.','error');}
    };

    const redoExplorerAction = async () => {
      const op=explorerRedo.value[explorerRedo.value.length-1];if(!op||clipboardBusy.value)return;
      explorerRedo.value=explorerRedo.value.slice(0,-1);
      try{
        if(op.kind==='rename'){
          const payload=await apiPost(op.type==='folder'?'rename_folder':'rename_document',{id:op.id,name:op.to});if(payload.state)applyState(payload.state);
        }else if(op.kind==='copy'){
          const before=explorerHistory.value.length;await pasteEntries(op.source,op.destination,{history:false});op.created=selectedContentItems.value.map(item=>({type:item.type,id:Number(item.id),name:item.name}));
        }else if(op.kind==='trash'){
          const newIds=[];const sources=(op.restored&&op.restored.length)?op.restored:op.items;
          for(const item of sources){const payload=await apiPost('trash_content',{item_type:item.type,item_id:Number(item.id)});if(payload.state)applyState(payload.state);if(payload.trash_id)newIds.push(Number(payload.trash_id));}
          op.trashIds=newIds;
        }else if(op.kind==='unlink'){
          const removed=[];
          for(const link of op.links||[]){const payload=await apiPost('delete_content_link',{id:Number(link.id)});if(payload.state)applyState(payload.state);if(payload.link)removed.push(payload.link);}
          if(removed.length)op.links=removed;
        }else if(op.kind==='delete-mixed'){
          const newIds=[];const sources=(op.restored&&op.restored.length)?op.restored:[];
          for(const item of sources){const payload=await apiPost('trash_content',{item_type:item.type,item_id:Number(item.id)});if(payload.state)applyState(payload.state);if(payload.trash_id)newIds.push(Number(payload.trash_id));}
          if(newIds.length)op.trashIds=newIds;
          const removed=[];
          for(const link of op.links||[]){const payload=await apiPost('delete_content_link',{id:Number(link.id)});if(payload.state)applyState(payload.state);if(payload.link)removed.push(payload.link);}
          if(removed.length)op.links=removed;
        }
        explorerHistory.value=[...explorerHistory.value,op];notify('Действие повторено.');
      }catch(error){explorerRedo.value=[...explorerRedo.value,op];notify(error.message || 'Не удалось повторить действие.','error');}
    };

    const startContentDrag = (event,type,item) => {
      if(!isContentSelected(type,item))selectContentItem(type,item);
      const items=clipboardEntries(type,item);if(!items.length)return;
      contentDrag.active=true;
      try{event.dataTransfer.effectAllowed='copy';event.dataTransfer.dropEffect='copy';event.dataTransfer.setData('text/plain',items.map(x=>x.name).join(', '));event.dataTransfer.setData('application/x-matercms-content',JSON.stringify(items));}catch(_){}
    };
    const contentDragOver = (event,folderId=null) => {if(!contentDrag.active)return;event.preventDefault();contentDrag.overFolderId=folderId??null;if(event.dataTransfer)event.dataTransfer.dropEffect='copy';};
    const contentDragLeave = event => {if(event.currentTarget===event.target)contentDrag.overFolderId=null;};
    const dropContent = async (event,folderId=null) => {
      if(!contentDrag.active)return;event.preventDefault();const items=clipboardEntries();contentDrag.active=false;contentDrag.overFolderId=null;if(items.length)await pasteEntries(items,folderId??route.folderId);
    };
    const endContentDrag = () => {contentDrag.active=false;contentDrag.overFolderId=null;};

    const keyboardUsesNativeClipboard = event => {
      const target = event?.target;
      if (target?.closest?.('input,textarea,select,[contenteditable="true"]')) return true;
      const selectedText = String(window.getSelection?.()?.toString?.() || '').trim();
      return selectedText.length > 0;
    };

    const refreshCurrentContext = async () => {
      if (route.kind === 'folder') { await loadState(); return; }
      if (route.kind === 'about') { await loadProductAbout(true); return; }
      if (route.kind === 'files') { await loadFiles(); return; }
      if (route.kind === 'data') { await loadDataSets(); return; }
      if (route.kind === 'forms') { await loadForms(); return; }
      if (route.kind === 'form' && currentForm.value?.id) { await loadFormSubmissions(currentForm.value.id); return; }
      if (route.kind === 'settings-projects') { await loadSettings(); await loadProjects(); if (can('members.view')) await loadProjectMembers(); else { projectMembers.value=[]; availableUsers.value=[]; } return; }
      if (route.kind === 'settings-team') { await loadSettings(); if (isSystemOwner.value) await loadUsers(); return; }
      if (['settings','settings-api','settings-languages','database'].includes(route.kind)) { await loadSettings(); return; }
    };

    const runContextAction = async action => {
      if (!action || action.disabled || !contextMenu.value) return;
      const menu = contextMenu.value;
      const item = menu.item;
      contextMenu.value = null;
      switch (action.id) {
        case 'open-folder': return openFolder(item.id);
        case 'open-document': return openDocument(item.id);
        case 'folder-api': await openFolder(item.id); drawer.value = 'api'; return;
        case 'document-api': await openDocument(item.id); drawer.value = 'api'; return;
        case 'open-linked-resource': return openLinkedResource(item);
        case 'linked-resource-api':
          if (item.resource_type === 'form') { await openForm(item.resource_id); drawer.value = 'form-api'; }
          else { await openDataSet(item.resource_id); drawer.value = 'data-api'; }
          return;
        case 'open-new-tab': return openSelectedInNewTab(item ? {...item,type:menu.type} : null);
        case 'copy-content': return setContentClipboard(menu.type, item);
        case 'duplicate-content': return duplicateSelection();
        case 'paste-content': return pasteContent(route.folderId);
        case 'paste-into-folder': return pasteContent(item.id);
        case 'rename': contextMenu.value = menu; return renameFromMenu();
        case 'delete': return trashSelectionItems(false);
        case 'copy-path': return copy(contentPathForItem(item ? {...item,type:menu.type} : selectedSingleContent.value));
        case 'properties': return openContentProperties(item ? {...item,type:menu.type} : null);
        case 'preview-file': return previewFile(item);
        case 'open-file-usage': return openFileUsage(item);
        case 'copy-file-url': return copy(item.url || item.path || '');
        case 'open-file-original': if (item.url) window.open(item.url, '_blank', 'noopener,noreferrer'); return;
        case 'open-data': return openDataSet(item.id);
        case 'data-api': await openDataSet(item.id); drawer.value = 'data-api'; return;
        case 'delete-data': await openDataSet(item.id); return deleteCurrentDataSet();
        case 'open-form': return openForm(item.id);
        case 'form-api': await openForm(item.id); drawer.value = 'form-api'; return;
        case 'delete-form': await openForm(item.id); return deleteCurrentForm();
        case 'open-project': return switchProject(item);
        case 'rename-project': return renameProject(item);
        case 'delete-project': return deleteCurrentProject();
        case 'edit-user': return editUser(item);
        case 'delete-user': return deleteUser(item);
        case 'record-read': if (selectContextRecord(menu)) { recordDialog.open = true; recordDialog.mode = 'read'; } return;
        case 'record-edit': if (selectContextRecord(menu)) { recordDialog.open = true; recordDialog.mode = 'edit'; nextTick(() => document.querySelector('.record-editor-modal input:not([type=file]), .record-editor-modal textarea')?.focus()); } return;
        case 'record-delete': if (selectContextRecord(menu)) { recordDialog.open = true; return chooseRecordAction('delete'); } return;
        case 'open-submission': return openSubmission(item);
        case 'delete-submission': return deleteSubmission(item);
        case 'edit-member': return openMemberEditor(item);
        case 'remove-member': return removeProjectMember(item);
        case 'create-folder': return chooseCreate('folder');
        case 'create-document': return chooseCreate('document');
        case 'view-list': return setView('list');
        case 'view-grid': return setView('grid');
        case 'select-all': return selectAllContent();
        case 'invert-selection': return invertContentSelection();
        case 'undo-explorer': return undoExplorer();
        case 'redo-explorer': return redoExplorerAction();
        case 'sort-name': return setContentSort('name');
        case 'sort-updated': return setContentSort('updated');
        case 'trash': return openTrash();
        case 'cleanup-files': return cleanupFiles();
        case 'create-data': return openCreateData();
        case 'create-record-data': return createMultipleRecord('data');
        case 'open-data-api': drawer.value = 'data-api'; return;
        case 'open-data-fields': drawer.value = 'data-fields'; return;
        case 'data-view-list': return setRecordView('data','list');
        case 'data-view-grid': return setRecordView('data','grid');
        case 'create-form': return openCreateForm();
        case 'open-form-api': drawer.value = 'form-api'; return;
        case 'open-form-fields': formTab.value = 'fields'; return;
        case 'refresh-submissions': return currentForm.value?.id ? loadFormSubmissions(currentForm.value.id) : null;
        case 'create-record-document': return createMultipleRecord('document');
        case 'open-document-api': drawer.value = 'api'; return;
        case 'open-document-fields': drawer.value = 'fields'; return;
        case 'open-versions': return openVersions();
        case 'document-view-list': return setRecordView('document','list');
        case 'document-view-grid': return setRecordView('document','grid');
        case 'create-project': return openCreateProject();
        case 'create-user': return openCreateUser();
        case 'add-member': return openMemberEditor(null);
        case 'go-projects': return openProjects();
        case 'go-users': return openUsers();
        case 'go-api-settings': return openApiSettings();
        case 'go-language-settings': return openLanguageSettings();
        case 'go-team-settings': return openTeamSettings();
        case 'go-database': return openDatabaseSettings();
        case 'go-settings': return openSettings();
        case 'switch-database': return openDatabaseSwitcher();
        case 'refresh': return refreshCurrentContext();
      }
    };

    const openFileUsage = file => {
      contextMenu.value = null;
      if (file?.document_id) return openDocument(file.document_id);
      if (file?.data_set_id) return openDataSet(file.data_set_id);
      if (file?.form_id) return openForm(file.form_id);
      notify('У этого файла нет связанного раздела.', 'info');
      return false;
    };

    const openFileMenu = (event, file) => showContextMenu(event, { type: 'media', item: file });

    const previewFile = file => {
      const target = file || contextMenu.value?.item;
      if (!target) return;
      selectedFile.value = target;
      contextMenu.value = null;
      createMenu.value = null;
      userMenu.value = false;
    };

    const previewFileIndex = computed(() => {
      if (!selectedFile.value) return -1;
      return filteredMediaFiles.value.findIndex(item =>
        (item.url && selectedFile.value.url && item.url === selectedFile.value.url) ||
        (item.path && selectedFile.value.path && item.path === selectedFile.value.path) ||
        (item.name === selectedFile.value.name && Number(item.size || 0) === Number(selectedFile.value.size || 0))
      );
    });

    const previewHasPrevious = computed(() => previewFileIndex.value > 0);
    const previewHasNext = computed(() => previewFileIndex.value >= 0 && previewFileIndex.value < filteredMediaFiles.value.length - 1);

    const stepFilePreview = direction => {
      if (!selectedFile.value) return;
      const nextIndex = previewFileIndex.value + direction;
      if (nextIndex < 0 || nextIndex >= filteredMediaFiles.value.length) return;
      selectedFile.value = filteredMediaFiles.value[nextIndex];
    };

    const closeFilePreview = () => {
      selectedFile.value = null;
    };

    const fileUsageLabel = file => file?.document_id ? 'Перейти к разделу' : file?.data_set_id ? 'Перейти к данным' : file?.form_id ? 'Перейти к форме' : 'Нет связанного раздела';

    const cleanupFiles = async () => {
      const unusedCount = mediaFiles.value.filter(file => Number(file.reference_count || 0) === 0).length;
      if (!unusedCount) {
        notify('Неиспользуемых файлов нет.');
        return;
      }
      const allowed = await askConfirm({
        title: 'Очистить неиспользуемые файлы?',
        message: `Будет удалено ${unusedCount} ${plural(unusedCount, 'неиспользуемый файл', 'неиспользуемых файла', 'неиспользуемых файлов')}. Файлы, нужные текущему контенту или истории версий, MaterCMS сохранит.`,
        confirmLabel: 'Очистить',
        icon: 'bi-trash3',
      });
      if (!allowed) return;
      try {
        const payload = await apiPost('cleanup_files');
        mediaFiles.value = Array.isArray(payload.files) ? payload.files : [];
        notify(payload.message);
      } catch (error) {
        notify(error.message || 'Не удалось очистить файлы.', 'error');
      }
    };
    const openFolderFromNav = id => { drawer.value = null; openFolder(id); };
    const backFromDocument = () => openFolder(currentDocument.value?.folder_id || null);

    const childCount = folderId => state.folders.filter(folder => folder.parent_id === folderId).length + state.documents.filter(doc => doc.folder_id === folderId).length + (state.content_links || []).filter(link => link.folder_id === folderId).length;
    const plural = (n, one, few, many) => {
      const value = Math.abs(n) % 100;
      const last = value % 10;
      if (value > 10 && value < 20) return many;
      if (last > 1 && last < 5) return few;
      if (last === 1) return one;
      return many;
    };

    const switchProject = async project => {
      if (!project?.id || Number(project.id) === Number(state.current_project?.id)) { drawer.value = null; return; }
      if (route.kind === 'document' && !(await canLeaveDocument())) return;
      if (route.kind === 'data-set' && !(await flushDataAutosave())) return;
      if (route.kind === 'form' && !(await flushFormAutosave())) return;
      const stayOnProjects = route.kind === 'settings-projects';
      beginRouteTransition(stayOnProjects ? { settings:'projects' } : {});
      try {
        const payload = await apiPost('switch_project', { project_id: project.id });
        applyState(payload.state);
        documentSession += 1; formSession += 1; dataSession += 1;
        currentDocument.value = null; currentForm.value = null; currentDataSet.value = null; mediaFiles.value = []; forms.value = []; dataSets.value = [];
        drawer.value = null;
        if (stayOnProjects) {
          history.replaceState({}, '', `${baseUrl}?settings=projects`);
          parseRoute();
          await loadProjects();
          if (can('members.view')) await loadProjectMembers();
          else { projectMembers.value = []; availableUsers.value = []; }
        } else {
          history.pushState({}, '', baseUrl);
          parseRoute();
        }
        await finishRouteTransition();
        notify(`Выбран проект «${payload.state?.current_project?.name || project.name}».`);
      } catch (error) { pageNavigating.value = false; document.documentElement.classList.remove('matercms-route-loading'); notify(error.message || 'Не удалось выбрать проект.', 'error'); }
    };
    const selectProjectForAccess = event => {
      const id = Number(event?.target?.value || 0);
      const project = projects.value.find(item => Number(item.id) === id);
      if (project) switchProject(project);
    };

    const openCreateProject = () => { projectDialog.name = ''; modal.value = 'create-project'; drawer.value = null; nextTick(() => document.querySelector('.modal-card input')?.focus()); };
    const createProject = async () => {
      if (!projectDialog.name || busy.value) return;
      busy.value = true;
      try { const payload = await apiPost('create_project', { name: projectDialog.name }); applyState(payload.state); projects.value = state.projects; modal.value = null; if(route.kind==='settings-projects'){ history.replaceState({},'',`${baseUrl}?settings=projects`); parseRoute(); await loadProjects(); if(can('members.view')) await loadProjectMembers(); } else { history.replaceState({},'',baseUrl); parseRoute(); } notify(payload.message); }
      catch(error){ notify(error.message || 'Не удалось создать проект.','error'); } finally { busy.value=false; }
    };
    const renameProject = async project => {
      projectDialog.name = project?.name || state.current_project?.name || '';
      modal.value = 'rename-project';
    };
    const saveProjectName = async () => {
      if (!projectDialog.name || busy.value) return; busy.value=true;
      try { const payload=await apiPost('rename_project',{name:projectDialog.name}); applyState(payload.state); projects.value=state.projects; modal.value=null; notify(payload.message); }
      catch(error){notify(error.message,'error');} finally{busy.value=false;}
    };
    const deleteCurrentProject = async () => {
      const name=state.current_project?.name || 'проект';
      const allowed=await askConfirm({title:'Удалить проект?',message:`Проект «${name}» будет удалён вместе с контентом, формами, версиями и привязками пользователей. Это действие нельзя отменить.`,confirmLabel:'Удалить проект',icon:'bi-folder-x'});
      if(!allowed)return;
      try{const payload=await apiPost('delete_project');applyState(payload.state);projects.value=state.projects;if(route.kind==='settings-projects'){history.replaceState({},'',`${baseUrl}?settings=projects`);parseRoute();await loadProjects();if(can('members.view'))await loadProjectMembers();else{projectMembers.value=[];availableUsers.value=[];}}else{history.replaceState({},'',baseUrl);parseRoute();}notify(payload.message);}catch(error){notify(error.message,'error');}
    };

    const openCreateUser = () => {
      userDialog.id=null; userDialog.name=''; userDialog.email=''; userDialog.password=''; userDialog.system_role='user';
      modal.value='create-user';
      nextTick(()=>document.querySelector('.user-access-form input[type="text"]')?.focus());
    };
    const editUser = user => {
      userDialog.id=user.id; userDialog.name=user.name; userDialog.email=user.email; userDialog.password=''; userDialog.system_role=user.system_role || 'user';
      modal.value='edit-user';
      nextTick(()=>document.querySelector('.user-access-form input[type="text"]')?.focus());
    };
    const saveUser = async () => {
      if(!userDialog.name||!userDialog.email||busy.value)return;busy.value=true;
      try{
        const action=userDialog.id?'update_user':'create_user';
        const payload=await apiPost(action,{id:userDialog.id,name:userDialog.name,email:userDialog.email,password:userDialog.password});
        users.value=payload.users||users.value;
        modal.value=null;
        notify(payload.message);
      }catch(error){notify(error.message,'error');}finally{busy.value=false;}
    };
    const deleteUser = async user => {
      const allowed=await askConfirm({title:'Удалить пользователя?',message:`«${user.name}» потеряет доступ ко всем проектам MaterCMS.`,confirmLabel:'Удалить пользователя',icon:'bi-person-x'});if(!allowed)return;
      try{const payload=await apiPost('delete_user',{id:user.id});users.value=payload.users||[];notify(payload.message);}catch(error){notify(error.message,'error');}
    };

    const roleDefaults = role => ({
      owner:{'content.view':true,'content.edit':true,'data.view':true,'data.edit':true,'forms.view':true,'forms.edit':true,'files.view':true,'files.manage':true,'settings.view':true,'settings.edit':true,'members.view':true,'members.manage':true,'project.edit':true},
      admin:{'content.view':true,'content.edit':true,'data.view':true,'data.edit':true,'forms.view':true,'forms.edit':true,'files.view':true,'files.manage':true,'settings.view':true,'settings.edit':true,'members.view':true,'members.manage':true,'project.edit':true},
      editor:{'content.view':true,'content.edit':true,'data.view':true,'data.edit':true,'forms.view':true,'forms.edit':true,'files.view':true,'files.manage':true,'settings.view':true,'settings.edit':false,'members.view':false,'members.manage':false,'project.edit':false},
      viewer:{'content.view':true,'content.edit':false,'data.view':true,'data.edit':false,'forms.view':true,'forms.edit':false,'files.view':true,'files.manage':false,'settings.view':true,'settings.edit':false,'members.view':false,'members.manage':false,'project.edit':false},
    }[role] || {});
    const openMemberEditor = member => {
      selectedProjectMember.value = member || null;
      projectDialog.user_id = member?.id || null;
      projectDialog.role = member?.role || 'editor';
      projectDialog.permissions = JSON.parse(JSON.stringify(member?.permissions || roleDefaults(projectDialog.role)));
      drawer.value = 'member';
    };
    const memberRoleChanged = () => { projectDialog.permissions = { ...roleDefaults(projectDialog.role) }; };
    const saveProjectMember = async () => {
      if(!projectDialog.user_id)return;
      try{const payload=await apiPost('save_project_member',{user_id:projectDialog.user_id,role:projectDialog.role,permissions:projectDialog.permissions});projectMembers.value=payload.members||[];drawer.value=null;notify(payload.message);}catch(error){notify(error.message,'error');}
    };
    const removeProjectMember = async member => {
      const allowed=await askConfirm({title:'Убрать из проекта?',message:`«${member.name}» больше не сможет открывать проект «${state.current_project?.name}».`,confirmLabel:'Убрать',icon:'bi-person-dash'});if(!allowed)return;
      try{const payload=await apiPost('remove_project_member',{user_id:member.id});projectMembers.value=payload.members||[];notify(payload.message);}catch(error){notify(error.message,'error');}
    };

    const openCreate = event => {
      dialog.name = '';
      dialog.type = '';
      dialog.target = null;
      dialog.mode = 'single';
      contextMenu.value = null;
      if (createMenu.value) {
        createMenu.value = null;
        return;
      }
      const target = event?.currentTarget || event?.target;
      const rect = target?.getBoundingClientRect?.();
      const width = 238;
      const height = 214;
      const x = rect ? Math.max(12, Math.min(rect.right - width, window.innerWidth - width - 12)) : 20;
      let y = rect ? rect.bottom + 8 : 70;
      if (y + height > window.innerHeight - 12 && rect) y = Math.max(12, rect.top - height - 8);
      createMenu.value = { x, y };
    };

    const openContentLinkPicker = async type => {
      createMenu.value = null;
      contentLinkDialog.type = type === 'form' ? 'form' : 'data';
      contentLinkDialog.items = [];
      contentLinkDialog.selected = [];
      contentLinkDialog.loading = true;
      modal.value = 'link-resource';
      try {
        const payload = await apiGet('content_link_options', {
          type: contentLinkDialog.type,
          folder_id: route.folderId ? String(route.folderId) : '0',
        });
        contentLinkDialog.items = Array.isArray(payload.items) ? payload.items : [];
        contentLinkDialog.selected = Array.isArray(payload.selected) ? payload.selected.map(Number) : [];
      } catch (error) {
        modal.value = null;
        notify(error.message || 'Не удалось загрузить ресурсы.','error');
      } finally { contentLinkDialog.loading = false; }
    };

    const toggleContentLinkSelection = id => {
      id = Number(id);
      contentLinkDialog.selected = contentLinkDialog.selected.includes(id)
        ? contentLinkDialog.selected.filter(item => Number(item) !== id)
        : [...contentLinkDialog.selected,id];
    };

    const saveContentLinks = async () => {
      if (contentLinkDialog.saving) return;
      contentLinkDialog.saving = true;
      try {
        const payload = await apiPost('save_content_links', {
          type: contentLinkDialog.type,
          folder_id: route.folderId || null,
          resource_ids: contentLinkDialog.selected,
        });
        if (payload.state) applyState(payload.state);
        modal.value = null;
        notify(payload.message || 'Связи обновлены.');
      } catch (error) { notify(error.message || 'Не удалось сохранить связи.','error'); }
      finally { contentLinkDialog.saving = false; }
    };

    const openLinkedResource = link => {
      if (!link) return;
      if (link.resource_type === 'form') return openForm(link.resource_id);
      return openDataSet(link.resource_id);
    };

    const chooseCreate = type => {
      createMenu.value = null;
      if (type === 'data' || type === 'form') return openContentLinkPicker(type);
      dialog.name = '';
      dialog.type = type;
      dialog.mode = 'single';
      modal.value = type === 'folder' ? 'create-folder' : 'create-document';
      nextTick(() => document.querySelector('.modal-card input')?.focus());
    };

    const createFolder = async () => {
      if (!dialog.name || busy.value) return;
      busy.value = true;
      try {
        const payload = await apiPost('create_folder', { name: dialog.name, parent_id: route.kind === 'folder' ? route.folderId : currentDocument.value?.folder_id || null });
        applyState(payload.state);
        modal.value = null;
        notify(payload.message);
        await navigate({ folder: payload.id });
      } catch (error) { notify(error.message, 'error'); }
      finally { busy.value = false; }
    };

    const createDocument = async () => {
      if (!dialog.name || busy.value) return;
      busy.value = true;
      try {
        const folderId = route.kind === 'folder' ? route.folderId : currentDocument.value?.folder_id || null;
        const payload = await apiPost('create_document', { name: dialog.name, folder_id: folderId, mode: dialog.mode });
        applyState(payload.state);
        modal.value = null;
        notify(payload.message);
        await navigate({ doc: payload.id });
      } catch (error) { notify(error.message, 'error'); }
      finally { busy.value = false; }
    };

    const openCreateForm = () => {
      dialog.name = '';
      dialog.type = 'form';
      modal.value = 'create-form';
      nextTick(() => document.querySelector('.modal-card input')?.focus());
    };

    const createForm = async () => {
      if (!dialog.name || busy.value) return;
      busy.value = true;
      try {
        const payload = await apiPost('create_form', { name: dialog.name });
        forms.value = Array.isArray(payload.forms) ? payload.forms : forms.value;
        modal.value = null;
        notify(payload.message || 'Форма создана.');
        await navigate({ form: payload.id });
      } catch (error) { notify(error.message || 'Не удалось создать форму.', 'error'); }
      finally { busy.value = false; }
    };

    const scheduleFormAutosave = (delay = autosaveDelay) => {
      if (formAutosaveTimer) window.clearTimeout(formAutosaveTimer);
      formAutosaveTimer = window.setTimeout(() => {
        formAutosaveTimer = null;
        saveForm({ silent: true });
      }, Math.max(200, Number(delay || autosaveDelay)));
    };

    const markFormDirty = (delay = autosaveDelay) => {
      if (!currentForm.value) return;
      formDirty.value = true;
      formSaveState.value = 'pending';
      formChangeSerial += 1;
      scheduleFormAutosave(delay);
    };

    const formSlugifyKey = value => String(value || '')
      .trim().toLocaleLowerCase('ru')
      .replace(/[а-яё]/g, char => ({
        а:'a',б:'b',в:'v',г:'g',д:'d',е:'e',ё:'e',ж:'zh',з:'z',и:'i',й:'y',к:'k',л:'l',м:'m',н:'n',о:'o',п:'p',р:'r',с:'s',т:'t',у:'u',ф:'f',х:'h',ц:'c',ч:'ch',ш:'sh',щ:'sch',ъ:'',ы:'y',ь:'',э:'e',ю:'yu',я:'ya'
      }[char] || char))
      .replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '') || 'field';

    const uniqueFormFieldKey = seed => {
      const used = new Set((currentForm.value?.schema || []).map(field => field.key));
      const base = formSlugifyKey(seed);
      let key = base, i = 2;
      while (used.has(key)) key = `${base}_${i++}`;
      return key;
    };

    const addFormField = () => {
      if (!currentForm.value) return;
      currentForm.value.schema.push({
        _uid: `form-${Date.now()}-${Math.random()}`,
        label: 'Новое поле',
        key: uniqueFormFieldKey('new_field'),
        type: 'text',
        required: false,
        placeholder: '',
        options: [],
        optionsText: '',
        multiple: false,
      });
      markFormDirty();
    };

    const removeFormField = async index => {
      const field = currentForm.value?.schema?.[index];
      if (!field) return;
      const allowed = await askConfirm({
        title: 'Удалить поле формы?',
        message: `Поле «${field.label}» исчезнет из формы. Уже полученные заявки сохранят старые данные.`,
        confirmLabel: 'Удалить поле',
        icon: 'bi-ui-checks-grid',
      });
      if (!allowed) return;
      currentForm.value.schema.splice(index, 1);
      markFormDirty();
    };

    const formFieldLabelChanged = (field, index) => {
      if (!field) return;
      const siblings = currentForm.value?.schema || [];
      const currentKey = String(field.key || '');
      const autoLike = !currentKey || currentKey === 'field' || currentKey.startsWith('new_field');
      if (autoLike) {
        const base = formSlugifyKey(field.label || `field_${index + 1}`);
        const used = new Set(siblings.filter(item => item !== field).map(item => item.key));
        let key = base, i = 2;
        while (used.has(key)) key = `${base}_${i++}`;
        field.key = key;
      }
      markFormDirty();
    };

    const formFieldTypeChanged = field => {
      if (!field) return;
      if (field.type !== 'select') { field.options = []; field.optionsText = ''; }
      if (field.type !== 'file') field.multiple = false;
      markFormDirty();
    };

    const formOptionsChanged = field => {
      field.options = String(field.optionsText || '').split(/\r?\n|,/).map(item => item.trim()).filter(Boolean);
      markFormDirty();
    };

    const saveForm = async ({ silent = true } = {}) => {
      if (!currentForm.value || !formDirty.value) return true;
      if (activeFormSavePromise) return activeFormSavePromise;
      if (formAutosaveTimer) window.clearTimeout(formAutosaveTimer);
      formAutosaveTimer = null;

      const formId = currentForm.value.id;
      const sessionAtStart = formSession;
      const serialAtStart = formChangeSerial;
      const nameSnapshot = currentForm.value.name;
      const successSnapshot = currentForm.value.success_message;
      const schemaSnapshot = currentForm.value.schema.map(({ _uid, optionsText, ...field }) => ({
        ...field,
        options: field.type === 'select' ? String(optionsText || '').split(/\r?\n|,/).map(x => x.trim()).filter(Boolean) : [],
        multiple: field.type === 'file' && field.multiple === true,
      }));

      formSaving.value = true;
      formSaveState.value = 'saving';
      const formSavePromise = (async () => {
        try {
          const payload = await apiPost('save_form', {
            id: formId,
            name: nameSnapshot,
            schema: schemaSnapshot,
            success_message: successSnapshot,
          });
          // The user may have navigated away and even reopened another form while this request
          // was in flight. In that case this response belongs to an obsolete editor session.
          if (sessionAtStart !== formSession || !currentForm.value || Number(currentForm.value.id) !== Number(formId)) return true;

          mergeFormBackgroundState(payload);
          formLastSavedAt.value = payload.form?.updated_at || new Date().toISOString();
          if (formChangeSerial === serialAtStart) {
            formDirty.value = false;
            formSaveState.value = 'saved';
          } else {
            // Newer keystrokes stay untouched and are queued for the next save.
            formSaveState.value = 'pending';
          }
          return true;
        } catch (error) {
          if (sessionAtStart === formSession) {
            formSaveState.value = 'error';
            if (!silent) notify(error.message || 'Не удалось сохранить форму.', 'error');
          }
          return false;
        } finally {
          if (sessionAtStart === formSession) {
            formSaving.value = false;
            if (activeFormSavePromise === formSavePromise) activeFormSavePromise = null;
            if (formDirty.value && formSaveState.value !== 'error') scheduleFormAutosave(450);
          } else if (activeFormSavePromise === formSavePromise) {
            activeFormSavePromise = null;
          }
        }
      })();
      activeFormSavePromise = formSavePromise;
      return formSavePromise;
    };

    const openSubmission = async submission => {
      selectedSubmission.value = submission;
      drawer.value = 'submission';
      if (submission?.status === 'new') {
        submission.status = 'read';
        if (currentForm.value) currentForm.value.new_count = Math.max(0, Number(currentForm.value.new_count || 0) - 1);
        const summary = forms.value.find(item => item.id === currentForm.value?.id);
        if (summary) summary.new_count = Math.max(0, Number(summary.new_count || 0) - 1);
        try { await apiPost('mark_submission', { id: submission.id, status: 'read' }); } catch (_) {}
      }
    };

    const deleteSubmission = async submission => {
      if (!submission) return;
      const allowed = await askConfirm({
        title: 'Удалить заявку?',
        message: 'Заявка и прикреплённые к ней файлы будут удалены. Это действие нельзя отменить.',
        confirmLabel: 'Удалить заявку',
        icon: 'bi-inbox',
      });
      if (!allowed) return;
      try {
        const payload = await apiPost('delete_submission', { id: submission.id });
        formSubmissions.value = formSubmissions.value.filter(item => item.id !== submission.id);
        if (selectedSubmission.value?.id === submission.id) { selectedSubmission.value = null; drawer.value = null; }
        if (currentForm.value) {
          currentForm.value.submission_count = Math.max(0, Number(currentForm.value.submission_count || 0) - 1);
          if (submission.status === 'new') currentForm.value.new_count = Math.max(0, Number(currentForm.value.new_count || 0) - 1);
        }
        await loadForms();
        notify(payload.message || 'Заявка удалена.');
      } catch (error) { notify(error.message || 'Не удалось удалить заявку.', 'error'); }
    };

    const deleteCurrentForm = async () => {
      if (!currentForm.value) return;
      const allowed = await askConfirm({
        title: 'Удалить форму?',
        message: `Форма «${currentForm.value.name}» и все ${currentForm.value.submission_count || 0} заявок будут удалены.`,
        confirmLabel: 'Удалить форму',
        icon: 'bi-ui-checks-grid',
      });
      if (!allowed) return;
      try {
        const payload = await apiPost('delete_form', { id: currentForm.value.id });
        forms.value = Array.isArray(payload.forms) ? payload.forms : [];
        formDirty.value = false;
        currentForm.value = null;
        notify(payload.message || 'Форма удалена.');
        await navigate({ forms: true });
      } catch (error) { notify(error.message || 'Не удалось удалить форму.', 'error'); }
    };

    const submissionFieldLabel = key => currentForm.value?.schema?.find(field => field.key === key)?.label || key;
    const submissionFieldType = key => currentForm.value?.schema?.find(field => field.key === key)?.type || 'text';
    const openMenu = (event, type, item) => {
      if (['folder','document','resource-link'].includes(type) && !isContentSelected(type,item)) selectContentItem(type, item);
      showContextMenu(event, { type, item });
    };

    const renameFromMenu = () => {
      if (!contextMenu.value) return;
      dialog.type = contextMenu.value.type;
      dialog.target = contextMenu.value.item;
      dialog.name = contextMenu.value.item.name;
      contextMenu.value = null;
      modal.value = 'rename';
    };

    const renameCurrent = async () => {
      if (!dialog.target || !dialog.name || busy.value) return;
      busy.value = true;
      const previousName = String(dialog.target.name || '');
      const nextName = String(dialog.name || '').trim();
      try {
        const action = dialog.type === 'folder' ? 'rename_folder' : 'rename_document';
        const payload = await apiPost(action, { id: dialog.target.id, name: nextName });
        applyState(payload.state);
        if (currentDocument.value?.id === dialog.target.id && dialog.type === 'document') currentDocument.value.name = nextName;
        contentSelection.value = contentSelection.value.map(entry => entry.type===dialog.type && Number(entry.id)===Number(dialog.target.id) ? {...entry,name:nextName} : entry);
        if (previousName && previousName !== nextName) pushExplorerHistory({kind:'rename',type:dialog.type,id:Number(dialog.target.id),from:previousName,to:nextName});
        modal.value = null;
        notify(payload.message);
      } catch (error) { notify(error.message, 'error'); }
      finally { busy.value = false; }
    };

    const deleteFromMenu = async () => {
      if (!contextMenu.value) return;
      const { type, item } = contextMenu.value;
      contextMenu.value = null;
      if (!isContentSelected(type,item)) selectContentItem(type,item);
      return trashSelectionItems(false);
    };

    const setView = mode => {
      viewMode.value = mode === 'list' ? 'list' : 'grid';
      localStorage.setItem('feather-view', viewMode.value);
    };

    const scheduleAutosave = (delay = autosaveDelay) => {
      clearAutosaveTimer();
      autosaveTimer = window.setTimeout(() => {
        autosaveTimer = null;
        saveDocument({ silent: true });
      }, Math.max(150, Number(delay || autosaveDelay)));
    };

    const markDirty = (delay = autosaveDelay) => {
      if (!currentDocument.value) return;
      const resolvedDelay = typeof delay === 'number' && Number.isFinite(delay) ? delay : autosaveDelay;
      dirty.value = true;
      saveState.value = 'pending';
      changeSerial += 1;
      scheduleAutosave(resolvedDelay);
    };

    const slugifyKey = value => String(value || '')
      .trim().toLocaleLowerCase('ru')
      .replace(/[а-яё]/g, char => ({
        а:'a',б:'b',в:'v',г:'g',д:'d',е:'e',ё:'e',ж:'zh',з:'z',и:'i',й:'y',к:'k',л:'l',м:'m',н:'n',о:'o',п:'p',р:'r',с:'s',т:'t',у:'u',ф:'f',х:'h',ц:'c',ч:'ch',ш:'sh',щ:'sch',ъ:'',ы:'y',ь:'',э:'e',ю:'yu',я:'ya'
      }[char] || char))
      .replace(/[^a-z0-9]+/g, '_')
      .replace(/^_+|_+$/g, '') || 'field';

    const uniqueFieldKey = seed => {
      if (!currentDocument.value) return 'field';
      const used = new Set(currentDocument.value.schema.map(field => field.key));
      const base = slugifyKey(seed);
      let key = base;
      let index = 2;
      while (used.has(key)) key = `${base}_${index++}`;
      return key;
    };

    const addField = () => {
      if (!currentDocument.value) return;
      const field = {
        _uid: `${Date.now()}-${Math.random()}`,
        label: 'Новое поле',
        key: uniqueFieldKey('new_field'),
        type: 'text',
        multiple: false,
      };
      currentDocument.value.schema.push(field);
      for (const dataset of allDocumentDatasets()) {
        if (currentDocument.value.mode === 'multiple' && Array.isArray(dataset)) dataset.forEach(item => { item[field.key] = defaultValue(field); });
        else if (dataset && !Array.isArray(dataset)) dataset[field.key] = defaultValue(field);
      }
      markDirty();
    };

    const removeField = async index => {
      if (!currentDocument.value) return;
      const field = currentDocument.value.schema[index];
      if (!field) return;
      const allowed = await askConfirm({
        title: 'Удалить поле?',
        message: `Поле «${field.label}» и его данные будут удалены из текущего раздела. В истории версий останется предыдущая копия.`,
        confirmLabel: 'Удалить поле',
        icon: 'bi-trash3',
      });
      if (!allowed) return;

      for (const dataset of allDocumentDatasets()) {
        if (currentDocument.value.mode === 'multiple' && Array.isArray(dataset)) {
          for (const item of dataset) delete item[field.key];
        } else if (dataset && !Array.isArray(dataset)) delete dataset[field.key];
      }
      if (currentDocument.value.mode === 'multiple') {
        for (const item of (activeDocumentData.value || [])) {
          cleanupPreview(uploadToken(field.key, item._uid));
          delete uploads[uploadToken(field.key, item._uid)];
        }
      } else {
        cleanupPreview(uploadToken(field.key));
        delete uploads[uploadToken(field.key)];
      }
      currentDocument.value.schema.splice(index, 1);
      markDirty();
    };

    const fieldLabelChanged = (field) => {
      if (!field.key) field.key = uniqueFieldKey(field.label);
      markDirty();
    };

    const fieldTypeChanged = field => {
      if (!isUploadType(field.type)) field.multiple = false;
      const apply = target => {
        if (!target) return;
        target[field.key] = normalizeFieldValue(field, target[field.key]);
      };
      for (const dataset of allDocumentDatasets()) {
        if (currentDocument.value?.mode === 'multiple' && Array.isArray(dataset)) dataset.forEach(apply);
        else apply(dataset);
      }
      markDirty();
    };

    const toggleFieldMultiple = async (field, event) => {
      if (!isUploadType(field.type)) return;
      const enabled = Boolean(event?.target?.checked);
      if (!enabled && field.multiple === true) {
        const currentDataset = activeDocumentData.value;
        const targets = currentDocument.value?.mode === 'multiple' ? (Array.isArray(currentDataset) ? currentDataset : []) : [currentDataset];
        const matchingTokens = Object.keys(uploads).filter(token => token === field.key || token.endsWith(`__${field.key}`));
        const willDrop = targets.some(target => Array.isArray(target?.[field.key]) && target[field.key].filter(Boolean).length > 1)
          || matchingTokens.some(token => Array.isArray(uploads[token]) && uploads[token].length > 1);
        if (willDrop) {
          const allowed = await askConfirm({
            title: 'Оставить только один файл?',
            message: 'В некоторых записях выбрано несколько файлов. После переключения MaterCMS оставит первый, а сохранённые ранее варианты останутся в истории версий.',
            confirmLabel: 'Оставить один',
            icon: 'bi-files',
          });
          if (!allowed) {
            if (event?.target) event.target.checked = true;
            return;
          }
        }
        for (const token of matchingTokens) {
          const previewList = Array.isArray(previews[token]) ? previews[token] : [];
          previewList.slice(1).forEach(url => { if (String(url).startsWith('blob:')) URL.revokeObjectURL(url); });
          if (previewList.length) previews[token] = previewList.slice(0, 1);
          if (Array.isArray(uploads[token])) uploads[token] = uploads[token].slice(0, 1);
        }
      }
      field.multiple = enabled;
      const apply = target => {
        if (!target) return;
        target[field.key] = normalizeFieldValue(field, target[field.key]);
      };
      for (const dataset of allDocumentDatasets()) {
        if (currentDocument.value?.mode === 'multiple' && Array.isArray(dataset)) dataset.forEach(apply);
        else apply(dataset);
      }
      markDirty();
    };

    const uploadToken = (key, itemUid = null) => itemUid ? `${itemUid}__${key}` : key;
    const fileAccept = type => ({
      image: 'image/jpeg,image/png,image/webp,image/gif,image/avif',
      video: 'video/mp4,video/webm,video/quicktime,video/x-matroska,.m4v,.mkv',
      audio: 'audio/mpeg,audio/wav,audio/ogg,audio/mp4,audio/aac,audio/flac,.m4a,.oga',
      file: '',
    }[type] || '');

    const uploadActionLabel = (field, hasValue) => {
      const singular = { image: 'изображение', video: 'видео', audio: 'аудио', file: 'файл' }[field?.type] || 'файл';
      const pluralName = { image: 'изображения', video: 'видео', audio: 'аудио', file: 'файлы' }[field?.type] || 'файлы';
      if (field?.multiple) return hasValue ? 'Добавить ещё' : `Выбрать ${pluralName}`;
      return hasValue ? `Заменить ${singular}` : `Выбрать ${singular}`;
    };
    const uploadHint = type => ({
      image: 'JPG, PNG, WebP, GIF, AVIF',
      video: 'MP4, WebM, MOV, MKV',
      audio: 'MP3, WAV, OGG, M4A, AAC, FLAC',
      file: 'Документы, архивы и другие безопасные файлы',
    }[type] || 'Файл');
    const fileKindLabel = file => {
      if (file?.kind === 'image') return 'IMG';
      if (file?.kind === 'video') return 'VIDEO';
      if (file?.kind === 'audio') return 'AUDIO';
      return String(file?.extension || 'FILE').slice(0, 7).toUpperCase();
    };
    const fileKindIcon = file => {
      if (file?.kind === 'image') return 'bi-image';
      if (file?.kind === 'video') return 'bi-camera-video';
      if (file?.kind === 'audio') return 'bi-music-note-beamed';
      if (file?.mime === 'application/pdf') return 'bi-file-earmark-pdf';
      const ext = String(file?.extension || '').toLowerCase();
      if (['zip','rar','7z','tar','gz'].includes(ext)) return 'bi-file-earmark-zip';
      if (['doc','docx','odt','rtf'].includes(ext)) return 'bi-file-earmark-word';
      if (['xls','xlsx','ods','csv'].includes(ext)) return 'bi-file-earmark-spreadsheet';
      if (['ppt','pptx','odp'].includes(ext)) return 'bi-file-earmark-slides';
      if (['txt','md','log'].includes(ext)) return 'bi-file-earmark-text';
      return 'bi-file-earmark';
    };

    const mediaTarget = itemUid => {
      const dataset = activeDocumentData.value;
      return currentDocument.value?.mode === 'multiple'
        ? (Array.isArray(dataset) ? dataset.find(item => item._uid === itemUid) : null) || activeItem.value
        : dataset;
    };

    const normalizedAssets = value => Array.isArray(value) ? value.filter(Boolean) : (value ? [value] : []);

    const assetValues = (field, itemUid = null) => {
      const token = uploadToken(field.key, itemUid);
      const target = mediaTarget(itemUid);
      const existing = normalizedAssets(target?.[field.key]);
      const pending = Array.isArray(previews[token]) ? previews[token] : (previews[token] ? [previews[token]] : []);
      return field?.multiple === true ? [...existing, ...pending] : (pending.length ? [pending[pending.length - 1]] : existing.slice(0, 1));
    };

    const assetValue = (key, itemUid = null) => {
      const field = currentDocument.value?.schema?.find(item => item.key === key) || { key, multiple: false };
      return assetValues(field, itemUid)[0] || '';
    };

    const assetName = value => {
      if (!value) return '';
      if (String(value).startsWith('blob:')) {
        for (const [token, urls] of Object.entries(previews)) {
          const list = Array.isArray(urls) ? urls : [urls];
          const index = list.indexOf(value);
          if (index >= 0) return uploads[token]?.[index]?.name || 'Новый файл';
        }
      }
      try {
        const url = new URL(String(value), window.location.href);
        return decodeURIComponent(url.pathname.split('/').pop() || 'Файл');
      } catch (_) {
        return String(value).split('/').pop() || 'Файл';
      }
    };

    const absoluteAsset = value => {
      try { return new URL(String(value || ''), window.location.origin).href; }
      catch (_) { return String(value || ''); }
    };

    const chooseAsset = (field, event, itemUid = null) => {
      const selected = Array.from(event.target.files || []);
      if (!selected.length) return;
      const maxBytes = Math.max(1, Number(config.uploadMaxMb || 100)) * 1024 * 1024;
      const tooLarge = selected.find(file => file.size > maxBytes);
      if (tooLarge) {
        notify(`«${tooLarge.name}» больше ${config.uploadMaxMb || 100} МБ.`, 'error');
        event.target.value = '';
        return;
      }
      const files = field?.multiple ? selected : selected.slice(0, 1);
      const token = uploadToken(field.key, itemUid);
      if (!field?.multiple) cleanupPreview(token);
      uploads[token] = field?.multiple ? [...(uploads[token] || []), ...files] : files;
      const urls = files.map(file => URL.createObjectURL(file));
      previews[token] = field?.multiple ? [...(previews[token] || []), ...urls] : urls;
      markDirty(300);
      event.target.value = '';
    };

    const removeAsset = async (field, value, itemUid = null) => {
      const allowed = await askConfirm({
        title: 'Убрать файл?',
        message: `«${assetName(value)}» будет убран из этого поля. После автосохранения файл останется только если он нужен другой записи или версии.`,
        confirmLabel: 'Убрать файл',
        icon: field?.type === 'image' ? 'bi-image' : field?.type === 'video' ? 'bi-camera-video' : field?.type === 'audio' ? 'bi-music-note-beamed' : 'bi-file-earmark',
      });
      if (!allowed) return;

      const token = uploadToken(field.key, itemUid);
      const previewList = Array.isArray(previews[token]) ? previews[token] : [];
      const pendingIndex = previewList.indexOf(value);
      if (pendingIndex >= 0) {
        URL.revokeObjectURL(previewList[pendingIndex]);
        previewList.splice(pendingIndex, 1);
        if (Array.isArray(uploads[token])) uploads[token].splice(pendingIndex, 1);
        if (!previewList.length) delete previews[token];
        if (!uploads[token]?.length) delete uploads[token];
        markDirty(300);
        return;
      }

      const target = mediaTarget(itemUid);
      if (!target) return;
      if (field?.multiple) {
        target[field.key] = normalizedAssets(target[field.key]).filter(item => item !== value);
      } else {
        target[field.key] = '';
      }
      markDirty(300);
    };

    const clearAsset = async (key, itemUid = null) => {
      const field = currentDocument.value?.schema?.find(item => item.key === key);
      const value = field ? assetValues(field, itemUid)[0] : '';
      if (field && value) await removeAsset(field, value, itemUid);
    };

    const cleanupPreview = key => {
      const current = previews[key];
      const list = Array.isArray(current) ? current : (current ? [current] : []);
      list.forEach(url => { if (String(url).startsWith('blob:')) URL.revokeObjectURL(url); });
      delete previews[key];
    };
    const cleanupPreviews = () => Object.keys(previews).forEach(cleanupPreview);

    const addItem = () => {
      if (!currentDocument.value || currentDocument.value.mode !== 'multiple') return null;
      const item = { _uid: makeUid() };
      for (const field of currentDocument.value.schema) item[field.key] = defaultValue(field);
      const dataset = activeDocumentData.value;
      if (!Array.isArray(dataset)) return null;
      dataset.push(item);
      activeItemUid.value = item._uid;
      markDirty();
      return item;
    };

    const deleteItem = async uid => {
      if (!currentDocument.value || currentDocument.value.mode !== 'multiple') return;
      const dataset = activeDocumentData.value;
      if (!Array.isArray(dataset)) return;
      const index = dataset.findIndex(item => item._uid === uid);
      if (index < 0) return;
      const allowed = await askConfirm({
        title: 'Удалить запись?',
        message: `Запись «${itemTitle(dataset[index], index)}» будет удалена. Предыдущий вариант останется в истории версий.`,
        confirmLabel: 'Удалить запись',
        icon: 'bi-trash3',
      });
      if (!allowed) return;
      const item = dataset[index];
      for (const field of currentDocument.value.schema) {
        const token = uploadToken(field.key, item._uid);
        cleanupPreview(token);
        delete uploads[token];
      }
      dataset.splice(index, 1);
      activeItemUid.value = dataset[index]?._uid || dataset[index - 1]?._uid || null;
      markDirty();
    };

    const itemTitle = (item, index) => {
      if (!currentDocument.value) return `Запись ${index + 1}`;
      const preferred = currentDocument.value.schema.find(field => ['text','textarea'].includes(field.type) && String(item?.[field.key] ?? '').trim());
      const value = preferred ? String(item[preferred.key]).trim() : '';
      return value || `Запись ${index + 1}`;
    };

    const dataUniqueFieldKey = seed => {
      const used = new Set((currentDataSet.value?.schema || []).map(field => field.key));
      const base = slugifyKey(seed);
      let key = base, index = 2;
      while (used.has(key)) key = `${base}_${index++}`;
      return key;
    };

    const cleanupDataPreview = token => {
      const list = Array.isArray(dataPreviews[token]) ? dataPreviews[token] : (dataPreviews[token] ? [dataPreviews[token]] : []);
      list.forEach(url => { if (String(url).startsWith('blob:')) URL.revokeObjectURL(url); });
      delete dataPreviews[token];
    };
    const cleanupDataPreviews = () => Object.keys(dataPreviews).forEach(cleanupDataPreview);
    const dataUploadToken = (key,itemUid=null) => itemUid ? `${itemUid}__${key}` : key;
    const dataTarget = itemUid => currentDataSet.value?.mode === 'multiple'
      ? (currentDataSet.value?.data || []).find(item => item._uid === itemUid) || dataActiveItem.value
      : currentDataSet.value?.data;
    const dataAssetValues = (field,itemUid=null) => {
      const token = dataUploadToken(field.key,itemUid);
      const existing = normalizedAssets(dataTarget(itemUid)?.[field.key]);
      const pending = Array.isArray(dataPreviews[token]) ? dataPreviews[token] : (dataPreviews[token] ? [dataPreviews[token]] : []);
      return field?.multiple ? [...existing,...pending] : (pending.length ? [pending[pending.length-1]] : existing.slice(0,1));
    };
    const chooseDataAsset = (field,event,itemUid=null) => {
      const selected = Array.from(event.target.files || []);
      if (!selected.length) return;
      const maxBytes = Math.max(1,Number(config.uploadMaxMb || 100))*1024*1024;
      const tooLarge = selected.find(file => file.size > maxBytes);
      if (tooLarge) { notify(`«${tooLarge.name}» больше ${config.uploadMaxMb || 100} МБ.`,'error'); event.target.value=''; return; }
      const files = field?.multiple ? selected : selected.slice(0,1);
      const token = dataUploadToken(field.key,itemUid);
      if (!field?.multiple) cleanupDataPreview(token);
      dataUploads[token] = field?.multiple ? [...(dataUploads[token] || []),...files] : files;
      const urls = files.map(file => URL.createObjectURL(file));
      dataPreviews[token] = field?.multiple ? [...(dataPreviews[token] || []),...urls] : urls;
      markDataDirty(300);
      event.target.value='';
    };
    const removeDataAsset = async (field,value,itemUid=null) => {
      const allowed = await askConfirm({title:'Убрать файл?',message:`«${assetName(value)}» будет убран из данных после автосохранения.`,confirmLabel:'Убрать файл',icon:'bi-file-earmark-x'});
      if (!allowed) return;
      const token = dataUploadToken(field.key,itemUid);
      const previewList = Array.isArray(dataPreviews[token]) ? dataPreviews[token] : [];
      const pendingIndex = previewList.indexOf(value);
      if (pendingIndex >= 0) {
        URL.revokeObjectURL(previewList[pendingIndex]); previewList.splice(pendingIndex,1);
        if (Array.isArray(dataUploads[token])) dataUploads[token].splice(pendingIndex,1);
        if (!previewList.length) delete dataPreviews[token];
        if (!dataUploads[token]?.length) delete dataUploads[token];
        markDataDirty(300); return;
      }
      const target = dataTarget(itemUid); if (!target) return;
      target[field.key] = field?.multiple ? normalizedAssets(target[field.key]).filter(item=>item!==value) : '';
      markDataDirty(300);
    };

    const scheduleDataAutosave = (delay=autosaveDelay) => {
      if (dataAutosaveTimer) window.clearTimeout(dataAutosaveTimer);
      dataAutosaveTimer = window.setTimeout(() => { dataAutosaveTimer=null; saveDataSet({silent:true}); },delay);
    };
    const markDataDirty = (delay=autosaveDelay) => {
      if (!currentDataSet.value || !can('data.edit')) return;
      const resolvedDelay = typeof delay === 'number' && Number.isFinite(delay) ? delay : autosaveDelay;
      dataDirty.value=true; dataSaveState.value='pending'; dataChangeSerial+=1; scheduleDataAutosave(resolvedDelay);
    };
    const flushDataAutosave = async () => {
      if (dataAutosaveTimer) window.clearTimeout(dataAutosaveTimer); dataAutosaveTimer=null;
      let attempts=0;
      while ((dataDirty.value || dataSaving.value) && attempts++<4) {
        const ok=await saveDataSet({silent:true});
        if (!ok && dataSaveState.value==='error') return false;
        if (dataSaving.value) await new Promise(resolve=>window.setTimeout(resolve,80));
      }
      return !dataDirty.value && !dataSaving.value;
    };

    const addDataField = () => {
      if (!currentDataSet.value) return;
      const field={_uid:`data-${Date.now()}-${Math.random()}`,label:'Новое поле',key:dataUniqueFieldKey('new_field'),type:'text',multiple:false,options:[],optionsText:'',source_data_set_id:null,relation_multiple:false,display_field:''};
      currentDataSet.value.schema.push(field);
      const targets=currentDataSet.value.mode==='multiple' ? currentDataSet.value.data : [currentDataSet.value.data];
      (targets || []).forEach(target=>{ if(target) target[field.key]=defaultValue(field); });
      markDataDirty();
    };
    const removeDataField = async index => {
      const field=currentDataSet.value?.schema?.[index]; if(!field) return;
      const allowed=await askConfirm({title:'Удалить поле?',message:`Поле «${field.label}» и его значения будут удалены из этого набора данных.`,confirmLabel:'Удалить поле',icon:'bi-trash3'});
      if(!allowed)return;
      const targets=currentDataSet.value.mode==='multiple' ? currentDataSet.value.data : [currentDataSet.value.data];
      (targets||[]).forEach(target=>{if(target) delete target[field.key];});
      Object.keys(dataUploads).filter(token=>token===field.key||token.endsWith(`__${field.key}`)).forEach(token=>{cleanupDataPreview(token);delete dataUploads[token];});
      currentDataSet.value.schema.splice(index,1); markDataDirty();
    };
    const dataFieldLabelChanged = field => { if(!field.key) field.key=dataUniqueFieldKey(field.label); markDataDirty(); };
    const dataFieldKeyChanged = (field,event) => {
      const oldKey=String(field.key||'');const raw=String(event?.target?.value||'');let next=slugifyKey(raw);
      const duplicate=(currentDataSet.value?.schema||[]).some(item=>item!==field&&item.key===next);
      if(!next||duplicate){if(event?.target)event.target.value=oldKey;notify(duplicate?'Такой slug поля уже используется.':'Введите корректный slug.','error');return;}
      if(next===oldKey)return;
      const targets=currentDataSet.value.mode==='multiple'?currentDataSet.value.data:[currentDataSet.value.data];
      (targets||[]).forEach(target=>{if(target&&Object.prototype.hasOwnProperty.call(target,oldKey)){target[next]=target[oldKey];delete target[oldKey];}});
      field.key=next;markDataDirty(300);
    };
    const dataFieldTypeChanged = field => {
      if(!isUploadType(field.type)) field.multiple=false;
      if(field.type!=='select'){field.options=[];field.optionsText='';}
      if(field.type!=='relation'){field.source_data_set_id=null;field.relation_multiple=false;field.display_field='';}
      else{field.source_data_set_id=null;field.relation_multiple=false;field.display_field='';}
      const targets=currentDataSet.value.mode==='multiple' ? currentDataSet.value.data : [currentDataSet.value.data];
      (targets||[]).forEach(target=>{if(target) target[field.key]=normalizeFieldValue(field,target[field.key]);}); markDataDirty();
    };
    const dataFieldOptionsChanged = field => { field.options=String(field.optionsText||'').split(/\r?\n/).map(x=>x.trim()).filter(Boolean); markDataDirty(); };
    const toggleDataFieldMultiple = (field,event) => { field.multiple=!!event?.target?.checked; const targets=currentDataSet.value.mode==='multiple'?currentDataSet.value.data:[currentDataSet.value.data];(targets||[]).forEach(target=>{if(target)target[field.key]=normalizeFieldValue(field,target[field.key]);});markDataDirty(); };
    const addDataItem = () => {
      if(currentDataSet.value?.mode!=='multiple') return null;
      const item={_uid:makeUid()}; for(const field of currentDataSet.value.schema)item[field.key]=defaultValue(field);
      currentDataSet.value.data.push(item);dataActiveItemUid.value=item._uid;markDataDirty();
      return item;
    };
    const deleteDataItem = async uid => {
      const rows=currentDataSet.value?.data;if(!Array.isArray(rows))return;const index=rows.findIndex(item=>item._uid===uid);if(index<0)return;
      const allowed=await askConfirm({title:'Удалить запись?',message:`Запись «${dataItemTitle(rows[index],index)}» будет удалена.`,confirmLabel:'Удалить запись',icon:'bi-trash3'});if(!allowed)return;
      for(const field of currentDataSet.value.schema){const token=dataUploadToken(field.key,uid);cleanupDataPreview(token);delete dataUploads[token];}
      rows.splice(index,1);dataActiveItemUid.value=rows[index]?._uid||rows[index-1]?._uid||null;markDataDirty();
    };
    const dataItemTitle = (item,index) => {
      const preferred=currentDataSet.value?.schema?.find(field=>['text','textarea','select'].includes(field.type)&&String(item?.[field.key]??'').trim());
      return preferred ? String(item[preferred.key]).trim() : `Запись ${index+1}`;
    };

    // ---------------------------------------------------------------------
    // Multiple record browser
    // ---------------------------------------------------------------------
    const setRecordView = (kind, mode) => {
      const next = mode === 'list' ? 'list' : 'grid';
      if (kind === 'data') {
        dataRecordViewMode.value = next;
        localStorage.setItem('feather-data-record-view', next);
      } else {
        documentRecordViewMode.value = next;
        localStorage.setItem('feather-document-record-view', next);
      }
    };

    const recordSchema = computed(() => recordDialog.kind === 'data'
      ? (currentDataSet.value?.schema || [])
      : (currentDocument.value?.schema || []));

    const recordRows = computed(() => recordDialog.kind === 'data'
      ? (Array.isArray(currentDataSet.value?.data) ? currentDataSet.value.data : [])
      : (Array.isArray(activeDocumentData.value) ? activeDocumentData.value : []));

    const recordTarget = computed(() => recordRows.value.find(item => String(item?._uid || '') === String(recordDialog.uid || '')) || null);
    const recordIndex = computed(() => Math.max(0, recordRows.value.findIndex(item => String(item?._uid || '') === String(recordDialog.uid || ''))));
    const recordDialogTitle = computed(() => {
      const item = recordTarget.value;
      const index = recordIndex.value;
      if (!item) return 'Запись';
      return recordDialog.kind === 'data' ? dataItemTitle(item,index) : itemTitle(item,index);
    });
    const recordCanEdit = computed(() => recordDialog.kind === 'data' ? can('data.edit') : can('content.edit'));

    const recordCover = (kind,item) => {
      const schema = kind === 'data' ? (currentDataSet.value?.schema || []) : (currentDocument.value?.schema || []);
      const imageField = schema.find(field => field.type === 'image' && normalizedAssets(item?.[field.key]).length);
      return imageField ? normalizedAssets(item?.[imageField.key])[0] : '';
    };

    const recordCardSubtitle = (kind,item,index) => {
      const schema = kind === 'data' ? (currentDataSet.value?.schema || []) : (currentDocument.value?.schema || []);
      const title = kind === 'data' ? dataItemTitle(item,index) : itemTitle(item,index);
      const candidate = schema.find(field => {
        if (!['text','textarea','select','number','date','datetime'].includes(field.type)) return false;
        const value = item?.[field.key];
        return String(value ?? '').trim() && String(value ?? '').trim() !== title;
      });
      if (!candidate) return `Запись ${index + 1}`;
      const value = String(item?.[candidate.key] ?? '').trim();
      return value.length > 90 ? `${value.slice(0,87)}…` : value;
    };

    const openRecordMenu = (kind,item,index) => {
      if (!item?._uid) return;
      if (kind === 'data') dataActiveItemUid.value = item._uid;
      else activeItemUid.value = item._uid;
      recordDialog.kind = kind;
      recordDialog.uid = item._uid;
      recordDialog.index = index;
      recordDialog.mode = 'menu';
      recordDialog.open = true;
    };

    const closeRecordDialog = () => {
      recordDialog.open = false;
      recordDialog.mode = 'menu';
    };

    const createMultipleRecord = kind => {
      const item = kind === 'data' ? addDataItem() : addItem();
      if (!item) return;
      const rows = kind === 'data' ? currentDataSet.value?.data : activeDocumentData.value;
      openRecordMenu(kind,item,Math.max(0,(rows?.length || 1)-1));
      recordDialog.mode = 'edit';
      nextTick(() => document.querySelector('.record-editor-modal input:not([type=file]), .record-editor-modal textarea')?.focus());
    };

    const chooseRecordAction = async action => {
      if (!recordTarget.value) return;
      if (action === 'read' || action === 'edit') {
        if (action === 'edit' && !recordCanEdit.value) return;
        recordDialog.mode = action;
        return;
      }
      if (action === 'delete') {
        if (!recordCanEdit.value) return;
        const kind = recordDialog.kind;
        const uid = recordDialog.uid;
        closeRecordDialog();
        if (kind === 'data') await deleteDataItem(uid);
        else await deleteItem(uid);
      }
    };

    const recordFieldValue = (field,value) => {
      if (field?.type === 'boolean') return value ? 'Да' : 'Нет';
      if (field?.type === 'relation') {
        const ids = field.relation_multiple ? (Array.isArray(value) ? value : []) : (value ? [value] : []);
        return ids.map(id => relationLabel(field,id)).filter(Boolean).join(', ') || '—';
      }
      if (Array.isArray(value)) return value.length ? value.map(item => String(item)).join(', ') : '—';
      if (value === null || value === undefined || value === '') return '—';
      return String(value);
    };

    const recordAssetValues = field => {
      if (!recordTarget.value) return [];
      if (recordDialog.kind === 'data') return dataAssetValues(field, recordDialog.uid);
      return assetValues(field, recordDialog.uid);
    };

    const relationCacheKey = field => `${Number(field?.source_data_set_id || 0)}:${String(field?.display_field || '')}`;
    const relationSource = field => dataSets.value.find(item => item.id === Number(field?.source_data_set_id || 0)) || null;
    const relationDisplayFields = field => relationSource(field)?.display_fields || [];
    const loadRelationRecords = async (field, force=false) => {
      const sourceId = Number(field?.source_data_set_id || 0);
      if (!sourceId) return null;
      const key = relationCacheKey(field);
      if (!force && relationCache[key]) return relationCache[key];
      const payload = await apiGet('data_relation_records',{source_id:String(sourceId),display_field:String(field?.display_field || '')});
      relationCache[key] = payload.source || {records:[]};
      return relationCache[key];
    };
    const preloadDataRelations = async () => {
      const fields=(currentDataSet.value?.schema || []).filter(field=>field.type==='relation' && Number(field.source_data_set_id)>0);
      await Promise.all(fields.map(field=>loadRelationRecords(field).catch(()=>null)));
    };
    const relationRecordById = (field,id) => {
      const key=relationCacheKey(field);const records=relationCache[key]?.records || [];
      return records.find(record=>record.id===Number(id)) || null;
    };
    const relationLabel = (field,id) => relationRecordById(field,id)?.label || (Number(id)>0 ? `Запись #${Number(id)}` : 'Не выбрано');
    const relationSelectedIds = (field,itemUid=null) => {
      const target=dataTarget(itemUid); if(!target)return [];
      const raw=target[field.key];
      return (Array.isArray(raw)?raw:(raw? [raw]:[])).map(Number).filter(id=>Number.isInteger(id)&&id>0);
    };
    const openRelationPicker = async (field,itemUid=null) => {
      if(!Number(field?.source_data_set_id||0)){notify('Сначала выберите источник связи в настройках поля.','error');drawer.value='data-fields';return;}
      relationPicker.open=true;relationPicker.fieldKey=field.key;relationPicker.itemUid=itemUid;relationPicker.sourceId=Number(field.source_data_set_id);relationPicker.displayField=String(field.display_field||'');relationPicker.search='';relationPicker.loading=true;
      try{await loadRelationRecords(field);}catch(error){notify(error.message||'Не удалось загрузить связанные данные.','error');relationPicker.open=false;}finally{relationPicker.loading=false;}
    };
    const closeRelationPicker = () => { relationPicker.open=false;relationPicker.search=''; };
    const relationPickerField = computed(() => currentDataSet.value?.schema?.find(field=>field.key===relationPicker.fieldKey) || null);
    const relationIsSelected = id => relationSelectedIds(relationPickerField.value,relationPicker.itemUid).includes(Number(id));
    const chooseRelationRecord = id => {
      const field=relationPickerField.value;const target=dataTarget(relationPicker.itemUid);if(!field||!target)return;
      id=Number(id);if(!Number.isInteger(id)||id<=0)return;
      if(field.relation_multiple){const current=relationSelectedIds(field,relationPicker.itemUid);target[field.key]=current.includes(id)?current.filter(item=>item!==id):[...current,id];markDataDirty(250);}
      else{target[field.key]=id;markDataDirty(250);closeRelationPicker();}
    };
    const removeRelationRecord = (field,id,itemUid=null) => {
      const target=dataTarget(itemUid);if(!target)return;
      if(field.relation_multiple)target[field.key]=relationSelectedIds(field,itemUid).filter(item=>item!==Number(id));
      else target[field.key]=null;
      markDataDirty(250);
    };
    const dataRelationSourceChanged = async field => {
      field.source_data_set_id=Number(field.source_data_set_id||0)||null;field.display_field='';
      const targets=currentDataSet.value.mode==='multiple'?currentDataSet.value.data:[currentDataSet.value.data];
      (targets||[]).forEach(target=>{if(target)target[field.key]=field.relation_multiple?[]:null;});
      markDataDirty(300);if(field.source_data_set_id)await loadRelationRecords(field,true).catch(()=>null);
    };
    const dataRelationModeChanged = field => {
      field.relation_multiple=field.relation_multiple===true;
      const targets=currentDataSet.value.mode==='multiple'?currentDataSet.value.data:[currentDataSet.value.data];
      (targets||[]).forEach(target=>{if(target)target[field.key]=normalizeFieldValue(field,target[field.key]);});markDataDirty(300);
    };
    const dataRelationDisplayChanged = async field => { field.display_field=String(field.display_field||'');markDataDirty(300);if(field.source_data_set_id)await loadRelationRecords(field,true).catch(()=>null); };

    const saveDataSet = async ({ silent = true } = {}) => {
      if (!currentDataSet.value || !dataDirty.value) return true;
      if (activeDataSavePromise) return activeDataSavePromise;
      if (dataAutosaveTimer) window.clearTimeout(dataAutosaveTimer);
      dataAutosaveTimer = null;

      const id = currentDataSet.value.id;
      const sessionAtStart = dataSession;
      const serialAtStart = dataChangeSerial;
      const schemaSnapshot = currentDataSet.value.schema.map(({ _uid, optionsText, ...field }) => ({
        ...field,
        options: Array.isArray(field.options) ? field.options : [],
      }));
      const valuesSnapshot = JSON.parse(JSON.stringify(currentDataSet.value.data));
      const uploadSnapshot = Object.entries(dataUploads)
        .map(([token, files]) => [token, Array.isArray(files) ? files.filter(file => file instanceof File).slice() : []])
        .filter(([, files]) => files.length);

      const uploadTargets = {};
      for (const [token] of uploadSnapshot) {
        if (currentDataSet.value.mode === 'multiple') {
          const [uid, ...keyParts] = token.split('__');
          const key = keyParts.join('__');
          const snapshotItem = Array.isArray(valuesSnapshot) ? valuesSnapshot.find(item => String(item?._uid || '') === String(uid)) : null;
          uploadTargets[token] = { mode: 'multiple', uid, key, valueAtStart: snapshotItem?.[key] };
        } else {
          uploadTargets[token] = { mode: 'single', key: token, valueAtStart: valuesSnapshot?.[token] };
        }
      }
      const pendingUnchanged = (token, sentFiles) => {
        const current = Array.isArray(dataUploads[token]) ? dataUploads[token] : [];
        return current.length === sentFiles.length && current.every((file, index) => file === sentFiles[index]);
      };

      dataSaving.value = true;
      dataSaveState.value = 'saving';
      const dataSavePromise = (async () => {
        try {
          const form = new FormData();
          form.append('_csrf', state.csrf);
          form.append('project_id', String(state.current_project?.id || ''));
          form.append('id', String(id));
          form.append('schema_json', JSON.stringify(schemaSnapshot));
          form.append('values_json', JSON.stringify(valuesSnapshot));
          uploadSnapshot.forEach(([token, files]) => files.forEach(file => form.append(`upload_${token}[]`, file)));

          const response = await fetch(`${config.adminApi}?action=save_data_set`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-CSRF-Token': state.csrf },
            body: form,
          });
          const payload = await parseResponse(response);
          if (sessionAtStart !== dataSession || !currentDataSet.value || Number(currentDataSet.value.id) !== Number(id)) return true;

          mergeDataBackgroundState(payload);
          Object.keys(relationCache).filter(key => key.startsWith(`${id}:`)).forEach(key => delete relationCache[key]);
          if (Number(payload.relations_cleaned || 0) > 0) notify('Связи с удалённой записью очищены автоматически.');

          // Merge only the URL created for an upload whose local selection/value did not
          // change during the request. Never replace the whole data set from the DB.
          for (const [token, sentFiles] of uploadSnapshot) {
            if (!pendingUnchanged(token, sentFiles)) continue;
            const target = uploadTargets[token];
            let localTarget = null;
            let savedTarget = null;
            if (target?.mode === 'single') {
              localTarget = currentDataSet.value.data;
              savedTarget = payload.data_set?.data;
            } else if (target?.mode === 'multiple') {
              localTarget = Array.isArray(currentDataSet.value.data)
                ? currentDataSet.value.data.find(item => String(item?._uid || '') === String(target.uid))
                : null;
              savedTarget = Array.isArray(payload.data_set?.data)
                ? payload.data_set.data.find(item => String(item?._uid || '') === String(target.uid))
                : null;
            }
            // If this exact field was edited again, keep the newer local value and let the next
            // save resolve it instead of resurrecting the older server snapshot.
            if (localTarget && savedTarget && sameJson(localTarget[target.key], target.valueAtStart)) {
              localTarget[target.key] = savedTarget[target.key] ?? localTarget[target.key];
              cleanupDataPreview(token);
              delete dataUploads[token];
            }
          }

          dataLastSavedAt.value = payload.data_set?.updated_at || new Date().toISOString();
          if (dataChangeSerial === serialAtStart) {
            dataDirty.value = false;
            dataSaveState.value = 'saved';
          } else {
            dataSaveState.value = 'pending';
          }
          return true;
        } catch (error) {
          if (sessionAtStart === dataSession) {
            dataSaveState.value = 'error';
            if (!silent) notify(error.message || 'Не удалось сохранить данные.', 'error');
            else notify(error.message || 'Автосохранение данных не удалось.', 'error');
          }
          return false;
        } finally {
          if (sessionAtStart === dataSession) {
            dataSaving.value = false;
            if (activeDataSavePromise === dataSavePromise) activeDataSavePromise = null;
            if (dataDirty.value && dataSaveState.value !== 'error') scheduleDataAutosave(450);
          } else if (activeDataSavePromise === dataSavePromise) {
            activeDataSavePromise = null;
          }
        }
      })();
      activeDataSavePromise = dataSavePromise;
      return dataSavePromise;
    };

    const openCreateData = () => { dataDialog.name='';dataDialog.slug='';dataDialog.mode='single';modal.value='create-data'; };
    const createDataSet = async () => {
      if(!dataDialog.name.trim())return;busy.value=true;
      try{const payload=await apiPost('create_data_set',{name:dataDialog.name,slug:dataDialog.slug,mode:dataDialog.mode});dataSets.value=payload.data_sets||[];modal.value=null;await openDataSet(payload.data_set.id);notify('Данные созданы.');}
      catch(error){notify(error.message||'Не удалось создать данные.','error');}finally{busy.value=false;}
    };
    const setDataApiEnabled = async enabled => {
      if(!currentDataSet.value||busy.value)return;
      if(!(await flushDataAutosave())) return;
      busy.value=true;
      try{const payload=await apiPost('set_data_api',{id:currentDataSet.value.id,enabled:!!enabled});mergeDataBackgroundState(payload);notify(enabled?'API данных включён.':'API данных выключен.');}
      catch(error){notify(error.message||'Не удалось изменить API.','error');}finally{busy.value=false;}
    };
    const deleteCurrentDataSet = async () => {
      if(!currentDataSet.value)return;const name=currentDataSet.value.name;
      try{
        const check=await apiGet('data_set_dependencies',{id:String(currentDataSet.value.id)});
        const deps=Array.isArray(check.dependencies)?check.dependencies:[];
        if(deps.length){
          const lines=deps.map(dep=>`• ${dep.data_set_name} → ${dep.field_label}`).join('\n');
          await askConfirm({title:`Невозможно удалить «${name}»`,message:`Этот набор используется в других данных:\n${lines}\n\nСначала удалите соответствующие поля «Связь».`,confirmLabel:'Понятно',cancelLabel:'Закрыть',tone:'primary',icon:'bi-link-45deg'});
          return;
        }
      }catch(error){notify(error.message||'Не удалось проверить связи.','error');return;}
      const allowed=await askConfirm({title:'Удалить данные?',message:`Набор «${name}» и все его записи будут удалены.`,confirmLabel:'Удалить данные',icon:'bi-trash3'});if(!allowed)return;
      busy.value=true;try{await apiPost('delete_data_set',{id:currentDataSet.value.id});notify('Данные удалены.');await openData();}catch(error){notify(error.message||'Не удалось удалить данные.','error');}finally{busy.value=false;}
    };

    const saveDocument = async ({ silent = true } = {}) => {
      if (!currentDocument.value || !dirty.value) return true;
      if (activeSavePromise) return activeSavePromise;

      clearAutosaveTimer();
      const documentId = currentDocument.value.id;
      const sessionAtStart = documentSession;
      const serialAtStart = changeSerial;
      const schemaSnapshot = currentDocument.value.schema.map(({ _uid, ...field }) => ({ ...field }));
      const valuesSnapshot = JSON.parse(JSON.stringify(activeDocumentData.value));
      const languageSnapshot = editorLanguage.value;
      const uploadSnapshot = Object.entries(uploads)
        .map(([token, files]) => [token, Array.isArray(files) ? files.filter(file => file instanceof File).slice() : []])
        .filter(([, files]) => files.length);
      const uploadTargets = {};
      for (const [token] of uploadSnapshot) {
        if (currentDocument.value.mode === 'multiple') {
          const [uid, ...keyParts] = token.split('__');
          const key = keyParts.join('__');
          const snapshotItem = Array.isArray(valuesSnapshot) ? valuesSnapshot.find(item => String(item?._uid || '') === String(uid)) : null;
          uploadTargets[token] = { mode: 'multiple', uid, key, valueAtStart: snapshotItem?.[key] };
        } else {
          uploadTargets[token] = { mode: 'single', key: token, valueAtStart: valuesSnapshot?.[token] };
        }
      }

      const pendingUnchanged = (token, sentFiles) => {
        const current = Array.isArray(uploads[token]) ? uploads[token] : [];
        return current.length === sentFiles.length && current.every((file, index) => file === sentFiles[index]);
      };

      saving.value = true;
      saveState.value = 'saving';
      const documentSavePromise = (async () => {
        try {
          const form = new FormData();
          form.append('_csrf', state.csrf);
          form.append('id', String(documentId));
          form.append('language', languageSnapshot);
          form.append('schema_json', JSON.stringify(schemaSnapshot));
          form.append('values_json', JSON.stringify(valuesSnapshot));
          uploadSnapshot.forEach(([token, files]) => files.forEach(file => form.append(`upload_${token}[]`, file)));

          const response = await fetch(`${config.adminApi}?action=save_document`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-CSRF-Token': state.csrf },
            body: form,
          });
          const payload = await parseResponse(response);
          if (sessionAtStart !== documentSession || !currentDocument.value || Number(currentDocument.value.id) !== Number(documentId)) return true;

          mergeDocumentBackgroundState(payload);

          // Upload URLs are the only user-data values that must come back from the server.
          // Patch only the field that produced the upload, by stable item _uid, and only when
          // that same field has not been changed again during the request.
          for (const [token, sentFiles] of uploadSnapshot) {
            if (!pendingUnchanged(token, sentFiles)) continue;
            const target = uploadTargets[token];
            let localTarget = null;
            let savedTarget = null;
            if (target?.mode === 'single') {
              localTarget = activeDocumentData.value;
              savedTarget = payload.active_data;
            } else if (target?.mode === 'multiple') {
              const localDataset = activeDocumentData.value;
              localTarget = Array.isArray(localDataset)
                ? localDataset.find(item => String(item?._uid || '') === String(target.uid))
                : null;
              savedTarget = Array.isArray(payload.active_data)
                ? payload.active_data.find(item => String(item?._uid || '') === String(target.uid))
                : null;
            }
            if (localTarget && savedTarget && sameJson(localTarget[target.key], target.valueAtStart)) {
              localTarget[target.key] = savedTarget[target.key] ?? localTarget[target.key];
              cleanupPreview(token);
              delete uploads[token];
            }
          }

          lastSavedAt.value = payload.document?.updated_at || new Date().toISOString();
          if (changeSerial === serialAtStart) {
            dirty.value = false;
            saveState.value = 'saved';
          } else {
            // Do not touch newer local values. They will be sent by the queued autosave below.
            saveState.value = 'pending';
          }
          if (drawer.value === 'versions') await loadVersions();
          return true;
        } catch (error) {
          if (sessionAtStart === documentSession) {
            saveState.value = 'error';
            if (error.status === 419) notify('Сессия устарела. Обновите страницу.', 'error');
            else notify(error.message || 'Автосохранение не удалось.', 'error');
          }
          return false;
        } finally {
          if (sessionAtStart === documentSession) {
            saving.value = false;
            if (activeSavePromise === documentSavePromise) activeSavePromise = null;
            if (dirty.value && saveState.value !== 'error') scheduleAutosave(450);
          } else if (activeSavePromise === documentSavePromise) {
            activeSavePromise = null;
          }
        }
      })();
      activeSavePromise = documentSavePromise;
      return documentSavePromise;
    };

    const loadVersions = async () => {
      if (!currentDocument.value) return;
      versionsLoading.value = true;
      try {
        const defaultLanguage = currentDocument.value?.i18n?.default_language || state.i18n?.default_language || 'ru';
        const revisionLanguage = editorLanguage.value === defaultLanguage ? '' : editorLanguage.value;
        const payload = await apiGet('versions', { id: String(currentDocument.value.id), language: revisionLanguage });
        versions.value = Array.isArray(payload.versions) ? payload.versions : [];
      } catch (error) {
        notify(error.message || 'Не удалось загрузить версии.', 'error');
      } finally {
        versionsLoading.value = false;
      }
    };

    const openVersions = async () => {
      drawer.value = 'versions';
      await flushAutosave();
      await loadVersions();
    };

    const restoreVersion = async version => {
      if (!currentDocument.value || !version?.id || restoring.value) return;
      const allowed = await askConfirm({
        title: 'Восстановить версию?',
        message: `Будет восстановлена версия от ${formatDateTime(version.created_at)}. Текущее состояние MaterCMS сначала сохранит в истории.`,
        confirmLabel: 'Восстановить',
        tone: 'primary',
        icon: 'bi-clock-history',
      });
      if (!allowed) return;
      restoring.value = true;
      try {
        await flushAutosave();
        const payload = await apiPost('restore_revision', {
          document_id: currentDocument.value.id,
          revision_id: version.id,
        });
        applyState(payload.state);
        cleanupPreviews();
        Object.keys(uploads).forEach(key => delete uploads[key]);
        const keepLanguage = editorLanguage.value;
        currentDocument.value = hydrateDocument(payload.document);
        editorLanguage.value = keepLanguage;
        ensureTranslation(keepLanguage);
        const restoredDataset = activeDocumentData.value;
        activeItemUid.value = currentDocument.value.mode === 'multiple' && Array.isArray(restoredDataset) ? (restoredDataset[0]?._uid || null) : null;
        versions.value = Array.isArray(payload.versions) ? payload.versions : [];
        dirty.value = false;
        saveState.value = 'saved';
        lastSavedAt.value = payload.document?.updated_at || new Date().toISOString();
        changeSerial += 1;
        notify(payload.message || 'Версия восстановлена.');
      } catch (error) {
        notify(error.message || 'Не удалось восстановить версию.', 'error');
      } finally {
        restoring.value = false;
      }
    };

    const copy = async text => {
      try {
        await navigator.clipboard.writeText(String(text || ''));
        notify('Скопировано.');
      } catch (_) { notify('Не удалось скопировать.', 'error'); }
    };

    const logout = async () => {
      try { await apiPost('logout'); } catch (_) {}
      window.location.href = baseUrl;
    };

    const toggleUserMenu = () => { userMenu.value = !userMenu.value; contextMenu.value = null; };

    const parseDateValue = value => {
      if (!value) return null;
      const normalized = String(value).replace(' ', 'T');
      const hasZone = /(?:Z|[+-]\d{2}:?\d{2})$/i.test(normalized);
      const date = new Date(hasZone ? normalized : `${normalized}Z`);
      return Number.isNaN(date.getTime()) ? null : date;
    };

    const formatTime = value => {
      if (!value) return '';
      const date = parseDateValue(value);
      if (!date) return '';
      return new Intl.DateTimeFormat('ru-RU', { hour: '2-digit', minute: '2-digit' }).format(date);
    };

    const formatDate = value => {
      if (!value) return 'сейчас';
      const date = parseDateValue(value);
      if (!date) return String(value).slice(0, 10);
      return new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'short', year: 'numeric' }).format(date);
    };

    const formatFileSize = bytes => {
      const size = Number(bytes || 0);
      if (size < 1024) return `${size} Б`;
      if (size < 1024 * 1024) return `${(size / 1024).toFixed(size < 10240 ? 1 : 0)} КБ`;
      return `${(size / (1024 * 1024)).toFixed(size < 10 * 1024 * 1024 ? 1 : 0)} МБ`;
    };

    const formatDateTime = value => {
      if (!value) return '—';
      const date = parseDateValue(value);
      if (!date) return value;
      return new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }).format(date);
    };

    const handlePopState = async () => {
      const previousUrl = routeUrl(
        route.kind === 'document' ? { doc: route.documentId } :
        route.kind === 'data-set' ? { data: route.dataSetId } :
        route.kind === 'form' ? { form: route.formId } :
        route.kind === 'files' ? { files: true } :
        route.kind === 'forms' ? { forms: true } :
        route.kind === 'data' ? { dataList: true } :
        route.kind === 'about' ? { about: true } :
        route.kind === 'settings' ? { settings: true } :
        route.kind === 'settings-api' ? { settings: 'api' } :
        route.kind === 'settings-languages' ? { settings: 'languages' } :
        route.kind === 'settings-projects' ? { settings: 'projects' } :
        route.kind === 'settings-team' ? { settings: 'team' } :
        route.kind === 'database' ? { database: true } :
        
        { folder: route.folderId }
      );
      let saved = true;
      if (route.kind === 'document' && (dirty.value || saving.value)) saved = await flushAutosave();
      if (route.kind === 'data-set' && (dataDirty.value || dataSaving.value)) saved = await flushDataAutosave();
      if (route.kind === 'form' && (formDirty.value || formSaving.value)) saved = await flushFormAutosave();
      if (!saved) {
        history.pushState({}, '', previousUrl);
        notify('Не удалось завершить автосохранение. Переход отменён.', 'error');
        return;
      }
      resetGlobalSearch();
      beginRouteTransition();
      try {
        await syncRoute();
        await finishRouteTransition();
      } catch (error) {
        pageNavigating.value = false;
        document.documentElement.classList.remove('matercms-route-loading');
        throw error;
      }
    };

    const handleViewportContextClose = event => {
      if (event?.target?.closest?.('.context-menu')) return;
      contextMenu.value = null;
    };

    const handleGlobalClick = event => {
      if (!event.target.closest('.user-popover') && !event.target.closest('.avatar-button')) userMenu.value = false;
      if (!event.target.closest('.context-menu') && !event.target.closest('.more-button')) contextMenu.value = null;
      if (!event.target.closest('.create-dropdown') && !event.target.closest('.create-main-button')) createMenu.value = null;
      if (!event.target.closest('.content-sort-menu-shell')) contentSortMenu.value = false;
      if (!event.target.closest('.global-search-shell')) closeGlobalSearch();
    };

    const handleKey = event => {
      const productDocumentOpen = aboutDocumentLoading.value || Boolean(aboutDocument.value);
      if (productDocumentOpen) {
        if (event.key === 'Escape') {
          event.preventDefault();
          closeProductDocument();
        }
        return;
      }

      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        globalSearchOpen.value = true;
        searchFocused.value = true;
        nextTick(() => document.querySelector('.global-search input')?.focus());
        return;
      }

      if (((event.ctrlKey || event.metaKey) && ['e','f'].includes(event.key.toLowerCase())) || event.key === 'F3') {
        if (!modal.value && !drawer.value) { event.preventDefault(); openGlobalSearch(); return; }
      }

      const shortcut = (event.ctrlKey || event.metaKey) ? event.key.toLowerCase() : '';
      if (drawer.value === 'trash' && !modal.value && !keyboardUsesNativeClipboard(event)) {
        if (shortcut === 'a') { event.preventDefault(); trashSelection.value = trashItems.value.map(item => Number(item.id)); return; }
        if (event.key === 'Delete' && trashSelection.value.length && can('content.edit')) { event.preventDefault(); deleteTrashForever(); return; }
        if (event.key === 'F5') { event.preventDefault(); loadTrash(); return; }
        // Other Explorer shortcuts must never leak through to the page behind the drawer.
        if (event.key !== 'Escape') return;
      }

      const explorerHotkeysActive = route.kind === 'folder' && !modal.value && !drawer.value && !contextMenu.value;
      if (explorerHotkeysActive && event.altKey && event.key === 'ArrowLeft') { event.preventDefault(); goBack(); return; }
      if (explorerHotkeysActive && event.altKey && event.key === 'ArrowRight') { event.preventDefault(); goForward(); return; }
      if (explorerHotkeysActive && event.altKey && event.key === 'ArrowUp') { event.preventDefault(); openParentFolder(); return; }
      if (explorerHotkeysActive && (event.key === 'ContextMenu' || (event.shiftKey && event.key === 'F10'))) { event.preventDefault(); openKeyboardContentMenu(); return; }

      if (explorerHotkeysActive && !keyboardUsesNativeClipboard(event)) {
        if (shortcut === 'a') { event.preventDefault(); selectAllContent(); return; }
        if (shortcut === 'c' && contentSelection.value.length) { event.preventDefault(); setContentClipboard(); return; }
        if (shortcut === 'v' && contentClipboard.value?.items?.length && can('content.edit')) { event.preventDefault(); pasteContent(route.folderId); return; }
        if (shortcut === 'd' && contentSelection.value.length && can('content.edit')) { event.preventDefault(); duplicateSelection(); return; }
        if (shortcut === 'z' && !event.shiftKey) { event.preventDefault(); undoExplorer(); return; }
        if (shortcut === 'y' || (shortcut === 'z' && event.shiftKey)) { event.preventDefault(); redoExplorerAction(); return; }
        if (shortcut === 'n' && event.shiftKey && can('content.edit')) { event.preventDefault(); chooseCreate('folder'); return; }
        if (event.altKey && event.key === 'Enter' && contentSelection.value.length === 1) { event.preventDefault(); openContentProperties(); return; }
        if (event.key === 'F2' && contentSelection.value.length === 1 && can('content.edit')) {
          event.preventDefault(); const item=selectedSingleContent.value; if(item && item.type!=='resource-link'){ dialog.type=item.type;dialog.target=item;dialog.name=item.name;modal.value='rename';nextTick(()=>document.querySelector('.modal-card input')?.select()); } return;
        }
        if (event.key === 'Delete' && contentSelection.value.length && can('content.edit')) { event.preventDefault(); trashSelectionItems(event.shiftKey); return; }
        if (event.key === 'Enter' && contentSelection.value.length === 1) { event.preventDefault(); if(event.ctrlKey||event.metaKey)openSelectedInNewTab(); else openSelectedContent(); return; }
        if (event.key === 'Backspace' && !event.altKey) { event.preventDefault(); openParentFolder(); return; }
        if (event.key === 'ArrowRight' || event.key === 'ArrowDown') { event.preventDefault(); selectAdjacentContent(1,event.shiftKey); return; }
        if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') { event.preventDefault(); selectAdjacentContent(-1,event.shiftKey); return; }
        if (event.key === 'Home') { event.preventDefault(); if(contentOrderedItems.value.length)selectContentItem(contentOrderedItems.value[0].type,contentOrderedItems.value[0]); return; }
        if (event.key === 'End') { event.preventDefault(); const items=contentOrderedItems.value;if(items.length)selectContentItem(items[items.length-1].type,items[items.length-1]); return; }
        if (event.key === 'F5') { event.preventDefault(); refreshCurrentContext(); return; }
      }

      if (event.key === 'Escape') {
        if (route.kind === 'folder' && contentSelection.value.length && !modal.value && !drawer.value && !contextMenu.value) { clearContentSelection(); return; }
        if (modal.value === 'database-switch' && databaseSwitching.value) return;
        if (confirmDialog.open) {
          closeConfirm(false);
          return;
        }
        if (recordDialog.open) {
          closeRecordDialog();
          return;
        }
        drawer.value = null;
        if (modal.value === 'api-token') apiSecret.value = '';
        if (selectedFile.value) {
          closeFilePreview();
          return;
        }
        modal.value = null;
        contextMenu.value = null;
        createMenu.value = null;
        userMenu.value = false;
      }
      if (selectedFile.value && event.key === 'ArrowLeft') {
        event.preventDefault();
        stepFilePreview(-1);
        return;
      }
      if (selectedFile.value && event.key === 'ArrowRight') {
        event.preventDefault();
        stepFilePreview(1);
        return;
      }
      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
        if (route.kind === 'document') { event.preventDefault(); saveDocument({ silent: false }); }
        if (route.kind === 'data-set') { event.preventDefault(); saveDataSet({ silent: false }); }
        if (route.kind === 'form') { event.preventDefault(); saveForm({ silent: false }); }
      }
    };

    const handleBeforeUnload = event => {
      if (!dirty.value && !saving.value && !formDirty.value && !formSaving.value && !dataDirty.value && !dataSaving.value) return;
      event.preventDefault();
      event.returnValue = '';
    };

    onMounted(async () => {
      applyTheme(themeMode.value);
      systemThemeQuery.addEventListener?.('change', handleSystemThemeChange);
      try {
        await loadState();
        await syncRoute();
      } catch (error) {
        if (error.status === 401) window.location.reload();
        else notify(error.message || 'Не удалось загрузить CMS.', 'error');
      } finally { loading.value = false; }
      window.addEventListener('popstate', handlePopState);
      window.addEventListener('beforeunload', handleBeforeUnload);
      window.addEventListener('resize', handleViewportContextClose);
      window.addEventListener('scroll', handleViewportContextClose, true);
      document.addEventListener('click', handleGlobalClick);
      document.addEventListener('keydown', handleKey);
    });

    onBeforeUnmount(() => {
      clearAutosaveTimer();
      if (formAutosaveTimer) window.clearTimeout(formAutosaveTimer);
      cleanupPreviews();
      cleanupDataPreviews();
      if (dataAutosaveTimer) window.clearTimeout(dataAutosaveTimer);
      if (globalSearchTimer) window.clearTimeout(globalSearchTimer);
      document.documentElement.classList.remove('drive-preview-open','product-document-open','matercms-modal-open');
      document.body.classList.remove('drive-preview-open','product-document-open','matercms-modal-open');
      window.removeEventListener('popstate', handlePopState);
      window.removeEventListener('beforeunload', handleBeforeUnload);
      window.removeEventListener('resize', handleViewportContextClose);
      window.removeEventListener('scroll', handleViewportContextClose, true);
      document.removeEventListener('click', handleGlobalClick);
      document.removeEventListener('keydown', handleKey);
      systemThemeQuery.removeEventListener?.('change', handleSystemThemeChange);
    });

    watch([aboutDocument, aboutDocumentLoading], ([documentValue, loadingValue]) => {
      const method = (Boolean(documentValue) || loadingValue) ? 'add' : 'remove';
      document.documentElement.classList[method]('product-document-open');
      document.body.classList[method]('product-document-open');
    });

    watch([
      modal,
      () => Boolean(recordDialog.open),
      () => Boolean(relationPicker.open),
      () => Boolean(confirmDialog.open),
      aboutDocument,
      aboutDocumentLoading,
      selectedFile,
    ], values => {
      const open = values.some(Boolean);
      const method = open ? 'add' : 'remove';
      document.documentElement.classList[method]('matercms-modal-open');
      document.body.classList[method]('matercms-modal-open');
    });

    watch(selectedFile, value => {
      const method = value ? 'add' : 'remove';
      document.documentElement.classList[method]('drive-preview-open');
      document.body.classList[method]('drive-preview-open');
    });

    watch(() => route.kind, () => { userMenu.value = false; });

    return {
      state,
      route,
      projects, projectMembers, availableUsers, users, projectLoading, usersLoading, selectedProjectMember, selectedUser, projectDialog, userDialog,
      can, isSystemOwner, currentProject, currentProjectRoleLabel,
      document: currentDocument,
      forms,
      form: currentForm,
      dataSets,
      dataSet: currentDataSet,
      dataLoading,
      dataDirty,
      dataSaving,
      dataSaveState,
      dataAutosaveText,
      dataActiveItemUid,
      dataActiveItem,
      dataActiveData,
      dataDialog,
      dataTypeNames,
      dataEndpoint,
      dataApiExampleCode,
      relationSourceDataSets,
      relationPicker,
      relationPickerField,
      relationPickerRecords,
      formSubmissions,
      selectedSubmission,
      formsLoading,
      formTab,
      formDirty,
      formSaving,
      formSaveState,
      formAutosaveText,
      formEndpoint,
      formExampleCode,
      formTypeNames,
      loading,
      pageNavigating,
      productAbout,
      productAboutLoading,
      aboutReleaseQuery,
      filteredProductReleases,
      aboutDocument,
      aboutDocumentLoading,
      routeSkeletonKind,
      routeViewKey,
      saving,
      busy,
      dirty,
      saveState,
      autosaveText,
      absoluteApiUrl,
      absoluteApiRoot,
      apiLanguage,
      apiSecret,
      apiExampleCode,
      apiAiPrompt,
      dataAiPrompt,
      formAiPrompt,
      chatGptPromptUrl,
      lastSavedAt,
      versions,
      versionsLoading,
      restoring,
      search,
      searchFocused,
      globalSearchQuery,
      globalSearchResults,
      globalSearchLoading,
      globalSearchOpen,
      globalSearchGroups,
      openGlobalSearch,
      closeGlobalSearch,
      clearGlobalSearch,
      openGlobalSearchResult,
      drawer,
      modal,
      confirmDialog,
      closeConfirm,
      userMenu,
      contextMenu,
      contextMenuStyle,
      contentSelection,
      contentClipboard,
      contentClipboardLabel,
      contentClipboardCount,
      contentSelectionCount,
      contentSort,
      contentSortMenu,
      contentProperties,
      selectedSingleContent,
      contentOrderedItems,
      trashItems,
      trashLoading,
      trashSelection,
      trashSelectionCount,
      allTrashSelected,
      isTrashSelected,
      contentDrag,
      explorerHistory,
      explorerRedo,
      clipboardBusy,
      isContentSelected,
      selectContentItem,
      activateContentItem,
      setContentClipboard,
      pasteContent,
      duplicateSelection,
      clearContentSelection,
      selectAllContent,
      invertContentSelection,
      setContentSort,
      openContentProperties,
      openSelectedContent,
      renameSelectedContent,
      openSelectedInNewTab,
      openParentFolder,
      goBack,
      goForward,
      refreshCurrentContext,
      openTrash,
      loadTrash,
      toggleTrashSelection,
      toggleAllTrash,
      restoreTrash,
      deleteTrashForever,
      emptyTrash,
      trashSelectionItems,
      undoExplorer,
      redoExplorerAction,
      startContentDrag,
      contentDragOver,
      contentDragLeave,
      dropContent,
      endContentDrag,
      smartContextMenuTitle,
      smartContextMenuEyebrow,
      smartContextActions,
      openPageContext,
      openObjectContext,
      openRecordContext,
      openSubmissionContext,
      runContextAction,
      createMenu,
      createMenuStyle,
      contentLinkDialog,
      mediaFiles,
      filesLoading,
      fileTypeFilter,
      fileTypeFilters,
      selectedFile,
      previewFileIndex,
      previewHasPrevious,
      previewHasNext,
      stepFilePreview,
      viewMode,
      submissionViewMode,
      documentRecordViewMode,
      dataRecordViewMode,
      recordDialog,
      recordTarget,
      recordSchema,
      recordDialogTitle,
      recordCanEdit,
      languageCatalog,
      languageSearch,
      settingsLoading,
      settingsSaving,
      apiResponseSaving,
      folderApiPreviewLoading,
      folderApiSettingsSaving,
      folderApiSettings,
      apiProjectEnabled,
      apiFullResponse,
      apiResponseModeLabel,
      currentApiResponseJson,
      documentResponseJson,
      folderResponseJson,
      dataResponseJson,
      formResponseJson,
      themeMode,
      setThemeMode,
      databaseInfo,
      databaseAvailability,
      databaseDialog,
      databaseSwitching,
      databaseCanSubmit,
      openDatabaseSwitcher,
      selectDatabaseDriver,
      changeDatabase,
      setApiAccessMode,
      setProjectApiEnabled,
      setFolderApiEnabled,
      saveFolderApiTreeSettings,
      setDocumentApiEnabled,
      setFormApiEnabled,
      setApiFullResponse,
      regenerateApiToken,
      closeApiSecret,
      selectedLanguages,
      filteredLanguages,
      dialog,
      activeItemUid,
      editorLanguage,
      documentLanguages,
      isDefaultEditorLanguage,
      activeDocumentData,
      currentVersionCount,
      activeItem,
      activeData,
      toasts,
      typeNames,
      initials,
      currentFolder,
      folderEndpoint,
      currentApiResourceEnabled,
      breadcrumbs,
      documentBreadcrumbs,
      filteredFolders,
      filteredDocuments,
      filteredContentLinks,
      contentVisibleCount,
      filteredForms,
      filteredDataSets,
      filteredFormSubmissions,
      filteredMediaFiles,
      fileTypeCount,
      modalTitle,
      modalEyebrow,
      goRoot,
      openFolder,
      openDocument,
      openFiles,
      openData,
      openDataSet,
      openForms,
      openSettings,
      openAbout,
      openProductDocument,
      closeProductDocument,
      renderMarkdown,
      renderMarkdownInline,
      openApiSettings,
      openLanguageSettings,
      openProjectSettings,
      openTeamSettings,
      openDatabaseSettings,
      openProjects, openUsers, switchProject, selectProjectForAccess, openCreateProject, createProject, renameProject, saveProjectName, deleteCurrentProject, openCreateUser, editUser, saveUser, deleteUser, openMemberEditor, memberRoleChanged, saveProjectMember, removeProjectMember,
      openForm,
      openFileUsage,
      openFileMenu,
      previewFile,
      closeFilePreview,
      fileUsageLabel,
      setFileTypeFilter,
      cleanupFiles,
      openFolderFromNav,
      backFromDocument,
      switchEditorLanguage,
      childCount,
      plural,
      openCreate,
      chooseCreate,
      openContentLinkPicker,
      toggleContentLinkSelection,
      saveContentLinks,
      openLinkedResource,
      createFolder,
      createDocument,
      openCreateForm,
      createForm,
      openCreateData,
      createDataSet,
      setDataApiEnabled,
      deleteCurrentDataSet,
      markDataDirty,
      addDataField,
      removeDataField,
      dataFieldLabelChanged,
      dataFieldKeyChanged,
      dataFieldTypeChanged,
      dataFieldOptionsChanged,
      toggleDataFieldMultiple,
      addDataItem,
      deleteDataItem,
      dataItemTitle,
      setRecordView,
      recordCover,
      recordCardSubtitle,
      openRecordMenu,
      closeRecordDialog,
      createMultipleRecord,
      chooseRecordAction,
      recordFieldValue,
      recordAssetValues,
      relationSource,
      relationDisplayFields,
      relationSelectedIds,
      relationLabel,
      openRelationPicker,
      closeRelationPicker,
      relationIsSelected,
      chooseRelationRecord,
      removeRelationRecord,
      dataRelationSourceChanged,
      dataRelationModeChanged,
      dataRelationDisplayChanged,
      dataAssetValues,
      chooseDataAsset,
      removeDataAsset,
      saveDataSet,
      markFormDirty,
      addFormField,
      removeFormField,
      formFieldLabelChanged,
      formFieldTypeChanged,
      formOptionsChanged,
      saveForm,
      openSubmission,
      deleteSubmission,
      deleteCurrentForm,
      submissionFieldLabel,
      submissionFieldType,
      openMenu,
      renameFromMenu,
      renameCurrent,
      deleteFromMenu,
      setView,
      setSubmissionView,
      languageSelected,
      toggleLanguage,
      setDefaultLanguage,
      setI18nEnabled,
      saveSettings,
      markDirty,
      addField,
      removeField,
      addItem,
      deleteItem,
      itemTitle,
      fieldLabelChanged,
      fieldTypeChanged,
      toggleFieldMultiple,
      isUploadType,
      fileAccept,
      uploadActionLabel,
      uploadHint,
      fileKindLabel,
      fileKindIcon,
      assetValue,
      assetValues,
      assetName,
      absoluteAsset,
      chooseAsset,
      removeAsset,
      clearAsset,
      saveDocument,
      openVersions,
      restoreVersion,
      copy,
      logout,
      toggleUserMenu,
      formatDate,
      formatTime,
      formatFileSize,
      formatDateTime,
    };
  },
});

app.component('folder-tree', FolderTree);
app.mount('#featherApp');
