(function($){
  'use strict';

  if (!window.FNF_SONGS_TAB || !$('#fnf-songs-app').length) {
    return;
  }

  var config = window.FNF_SONGS_TAB;
  var canEdit = !!config.canEdit;
  var restNonce = config.nonce || '';
  var profileUserId = parseInt(config.profileUserId, 10) || 0;
  var songs = [];
  var starterSongs = Array.isArray(config.starterSongs) ? config.starterSongs.map(function(row){
    return normalizeSong(row);
  }) : [];
  var lastUndoSnapshot = null;
  var manageSessionSnapshot = null;
  var manageSearchInit = false;
  var rowIdCounter = 1;
  var selectedIds = {};
  var isDirty = false;
  var isSaving = false;
  var seedActionPending = null;
  var pendingActionMessage = '';

  var viewState = {
    query: '',
    genre: '',
    decade: '',
    sort: 'title_asc',
    page: 1,
    perPage: parseInt(config.perPage, 10) || 25
  };

  var manageState = {
    query: '',
    genre: '',
    decade: '',
    sort: 'title_asc',
    page: 1,
    perPage: parseInt(config.perPage, 10) || 25,
    duplicateTitle: ''
  };

  var messages = $.extend({
    noSongsYet: 'No songs in your list yet. Use the search box above to add some.',
    noMatches: 'No songs match your current filters.',
    alreadyInList: 'That song is already in your list.',
    songAdded: 'Song added.',
    songRemoved: 'Song removed.',
    songsRemovedSuffix: ' songs removed.',
    noSongsSelected: 'No songs selected.',
    saveFailed: 'Save failed.',
    seedApplying: 'Applying starter pack...',
    seedFailed: 'Failed to apply starter pack.',
    undoDone: 'Last change undone.',
    duplicateTitleLabel: 'Possible duplicate titles',
    duplicateFilterLabel: 'Review filter active.',
    duplicateClearLabel: 'Clear',
    reviewLabel: 'Review',
    replaceConfirm: 'This will replace your current list with the starter pack. Continue?'
  }, config.messages || {});

  function cloneSongs(list) {
    return JSON.parse(JSON.stringify(list || []));
  }

  function nextRowId() {
    rowIdCounter++;
    return 'fnf_song_' + rowIdCounter + '_' + Math.random().toString(36).slice(2, 8);
  }

  function safeString(value) {
    return $.trim(String(value || ''));
  }

  function yearNumber(value) {
    var n = parseInt(value, 10);
    return isNaN(n) ? 0 : n;
  }

  function normalizeSong(row) {
    var out = {
      _rowId: row && row._rowId ? String(row._rowId) : nextRowId(),
      title: safeString(row && row.title),
      artist: safeString(row && row.artist),
      genre: safeString(row && row.genre),
      year: safeString(row && row.year),
      decade: safeString(row && row.decade),
      source: safeString(row && row.source),
      source_id: safeString(row && row.source_id)
    };

    if (!out.source) {
      out.source = 'manual';
    }

    return out;
  }

  function serializeSongsForSave() {
    return songs.map(function(row){
      return {
        title: row.title || '',
        artist: row.artist || '',
        genre: row.genre || '',
        year: row.year || '',
        decade: row.decade || '',
        source: row.source || '',
        source_id: row.source_id || ''
      };
    });
  }

  function saveSongsToHidden() {
    $('#songs_played_json').val(JSON.stringify(serializeSongsForSave()));
  }

  function loadSongsFromConfig() {
    var parsed = Array.isArray(config.songs) ? config.songs : [];
    songs = parsed.map(function(row){
      return normalizeSong(row);
    });
    saveSongsToHidden();
  }

  function exactSongKey(row) {
    return (safeString(row.title) + '|' + safeString(row.artist)).toLowerCase();
  }

  function titleOnlyKey(row) {
    return safeString(row.title).toLowerCase().replace(/\s+/g, ' ');
  }

  function canonicalSongTitle(value) {
    var s = safeString(value).toLowerCase();

    s = s.replace(/\(.*?\)/g, ' ');
    s = s.replace(/\[.*?\]/g, ' ');
    s = s.replace(/\b(live|acoustic|remaster(ed)?|mono|stereo|version|edit|radio edit|single version|album version|from soundtrack)\b/g, ' ');
    s = s.replace(/&/g, ' and ');
    s = s.replace(/[^a-z0-9\s]/g, ' ');
    s = s.replace(/\s+/g, ' ').replace(/^\s+|\s+$/g, '');

    return s;
  }

  function canonicalArtistKey(value) {
    var s = safeString(value).toLowerCase();
    s = s.replace(/&/g, ' and ');
    s = s.replace(/[^a-z0-9\s]/g, ' ');
    s = s.replace(/\s+/g, ' ').replace(/^\s+|\s+$/g, '');
    return s;
  }

  function duplicateSongKey(row) {
    return canonicalSongTitle(row.title) + '|' + canonicalArtistKey(row.artist);
  }

  function uniqueSortedValues(list, field) {
    var seen = {};
    var out = [];

    list.forEach(function(row){
      var value = safeString(row[field]);
      if (!value) {
        return;
      }
      if (!seen[value]) {
        seen[value] = true;
        out.push(value);
      }
    });

    out.sort(function(a, b){
      return a.localeCompare(b, undefined, { sensitivity: 'base' });
    });

    return out;
  }

  function computeTopCounts(list, field, limit) {
    var counts = {};
    var out = [];

    list.forEach(function(row){
      var value = safeString(row[field]);
      if (!value) {
        return;
      }
      counts[value] = (counts[value] || 0) + 1;
    });

    for (var key in counts) {
      if (counts.hasOwnProperty(key)) {
        out.push({ label: key, count: counts[key] });
      }
    }

    out.sort(function(a, b){
      if (b.count !== a.count) {
        return b.count - a.count;
      }
      return a.label.localeCompare(b.label, undefined, { sensitivity: 'base' });
    });

    return out.slice(0, limit || 3);
  }
    
  function getDuplicateGroups() {
    var counts = {};
    var groups = {};

    songs.forEach(function(row){
      var key = duplicateSongKey(row);
      if (!key || key === '|') {
        return;
      }
      counts[key] = (counts[key] || 0) + 1;
      if (!groups[key]) {
        groups[key] = [];
      }
      groups[key].push(row);
    });

    var out = [];
    for (var key in counts) {
      if (counts.hasOwnProperty(key) && counts[key] > 1) {
        out.push({
          key: key,
          label: groups[key][0].title + (groups[key][0].artist ? ' - ' + groups[key][0].artist : ''),
          count: counts[key]
        });
      }
    }

    out.sort(function(a, b){
      if (b.count !== a.count) {
        return b.count - a.count;
      }
      return a.label.localeCompare(b.label, undefined, { sensitivity: 'base' });
    });

    return out;
  }

  function computeSummaryData() {
    var artists = {};
    songs.forEach(function(row){
      if (row.artist) {
        artists[row.artist] = true;
      }
    });

    return {
      total: songs.length,
      artists: Object.keys(artists).length,
      topGenres: computeTopCounts(songs, 'genre', 3),
      topDecades: computeTopCounts(songs, 'decade', 3),
      duplicateTitles: getDuplicateGroups().length
    };
  }

  function summaryListText(items, emptyLabel) {
    if (!items.length) {
      return emptyLabel;
    }
    return items.map(function(item){
      return item.label + ' (' + item.count + ')';
    }).join(', ');
  }

  function escapeHtml(value) {
    return $('<div/>').text(value || '').html();
  }

  function renderDirtyNotice() {
    var html = '';

    if (!isDirty) {
      $('#fnf-songs-dirty').html('').stop(true, true).slideUp(120);
      $('.fnf-songs-save-btn').removeClass('is-dirty');
      return;
    }

    html += '<div class="fnf-songs-dirty-text">';

    if (pendingActionMessage) {
      html += escapeHtml(pendingActionMessage) + ' Save Changes to keep your edits.';
      if (lastUndoSnapshot) {
        html += ' <a href="#" class="fnf-songs-undo">' + escapeHtml(messages.undoLabel || 'Undo last change') + '</a>';
      }
    } else {
      html += escapeHtml(messages.savePending || 'You have unsaved changes. Use Save Changes to keep them.');
    }

    html += '</div>';

    $('#fnf-songs-dirty').html(html).stop(true, true).slideDown(120);
    $('.fnf-songs-save-btn').addClass('is-dirty');
  }

  function setDirty(flag) {
    isDirty = !!flag;

    if (!isDirty) {
      pendingActionMessage = '';
    }

    renderDirtyNotice();
  }

  function getFriendlyAjaxError(xhr, fallbackMessage) {
    var msg = '';

    if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
      msg = String(xhr.responseJSON.message);
    } else if (xhr && xhr.responseText) {
      msg = String(xhr.responseText);
    }

    if (msg && msg.indexOf('No route was found matching the URL and request method') !== -1) {
      return messages.routeErrorFriendly;
    }

    return msg || fallbackMessage;
  }

  function renderSummary() {
    var summary = computeSummaryData();
    var html = '';

    html += '<div class="fnf-summary-card">';
    html += '  <div class="fnf-summary-label">Total songs</div>';
    html += '  <div class="fnf-summary-value">' + summary.total + '</div>';
    html += '</div>';

    html += '<div class="fnf-summary-card">';
    html += '  <div class="fnf-summary-label">Artists</div>';
    html += '  <div class="fnf-summary-value">' + summary.artists + '</div>';
    html += '</div>';

    html += '<div class="fnf-summary-card">';
    html += '  <div class="fnf-summary-label">Top genres</div>';
    html += '  <div class="fnf-summary-text">' + escapeHtml(summaryListText(summary.topGenres, 'None')) + '</div>';
    html += '</div>';

    html += '<div class="fnf-summary-card">';
    html += '  <div class="fnf-summary-label">Top decades</div>';
    html += '  <div class="fnf-summary-text">' + escapeHtml(summaryListText(summary.topDecades, 'None')) + '</div>';
    html += '</div>';

    html += '<div class="fnf-summary-card fnf-summary-card-duplicates' + (canEdit && summary.duplicateTitles > 0 ? ' is-clickable' : '') + '"';
    if (canEdit && summary.duplicateTitles > 0) {
      html += ' role="button" tabindex="0"';
    }
    html += '>';
    html += '  <div class="fnf-summary-label">Possible duplicate titles</div>';
    html += '  <div class="fnf-summary-value">' + summary.duplicateTitles + '</div>';
    html += '</div>';

    $('#fnf-songs-summary').html(html);
  }
    
  function openDuplicateReviewFromSummary() {
    var groups = getDuplicateGroups();

    if (!canEdit || !groups.length) {
      return;
    }

    if ($('#fnf-songs-manage-panel').is(':hidden')) {
      openManageMode(false);
    }

    manageState.duplicateTitle = '';
    manageState.page = 1;
    renderManageTable();
    scrollToDuplicatePanelContext();
  }

  function populateSelect($select, values, selectedValue, emptyLabel) {
    var current = selectedValue || '';
    var html = '<option value="">' + escapeHtml(emptyLabel) + '</option>';

    values.forEach(function(value){
      html += '<option value="' + escapeHtml(value) + '"' + (value === current ? ' selected="selected"' : '') + '>' + escapeHtml(value) + '</option>';
    });

    $select.html(html);
  }

  function populateFilters() {
    var genres = uniqueSortedValues(songs, 'genre');
    var decades = uniqueSortedValues(songs, 'decade');

    populateSelect($('#fnf-songs-view-genre'), genres, viewState.genre, 'All genres');
    populateSelect($('#fnf-songs-view-decade'), decades, viewState.decade, 'All decades');

    if (canEdit) {
      populateSelect($('#fnf-songs-manage-genre'), genres, manageState.genre, 'All genres');
      populateSelect($('#fnf-songs-manage-decade'), decades, manageState.decade, 'All decades');
    }
  }

  function filterRows(list, state) {
    var q = safeString(state.query).toLowerCase();

    return list.filter(function(row){
      if (state.genre && row.genre !== state.genre) {
        return false;
      }

      if (state.decade && row.decade !== state.decade) {
        return false;
      }

      if (state.duplicateTitle && duplicateSongKey(row) !== state.duplicateTitle) {
        return false;
      }

      if (!q) {
        return true;
      }

      var haystack = [
        row.title,
        row.artist,
        row.genre,
        row.year,
        row.decade
      ].join(' ').toLowerCase();

      return haystack.indexOf(q) !== -1;
    });
  }

  function sortRows(list, sortKey) {
    var sorted = list.slice();

    sorted.sort(function(a, b){
      var av;
      var bv;

      switch (sortKey) {
        case 'title_desc':
          av = safeString(b.title);
          bv = safeString(a.title);
          return av.localeCompare(bv, undefined, { sensitivity: 'base' });

        case 'artist_asc':
          av = safeString(a.artist);
          bv = safeString(b.artist);
          if (av === bv) {
            return safeString(a.title).localeCompare(safeString(b.title), undefined, { sensitivity: 'base' });
          }
          return av.localeCompare(bv, undefined, { sensitivity: 'base' });

        case 'artist_desc':
          av = safeString(b.artist);
          bv = safeString(a.artist);
          if (av === bv) {
            return safeString(a.title).localeCompare(safeString(b.title), undefined, { sensitivity: 'base' });
          }
          return av.localeCompare(bv, undefined, { sensitivity: 'base' });

        case 'year_desc':
          av = yearNumber(b.year);
          bv = yearNumber(a.year);
          if (av === bv) {
            return safeString(a.title).localeCompare(safeString(b.title), undefined, { sensitivity: 'base' });
          }
          return av - bv;

        case 'year_asc':
          av = yearNumber(a.year);
          bv = yearNumber(b.year);
          if (av === bv) {
            return safeString(a.title).localeCompare(safeString(b.title), undefined, { sensitivity: 'base' });
          }
          return av - bv;

        case 'title_asc':
        default:
          av = safeString(a.title);
          bv = safeString(b.title);
          return av.localeCompare(bv, undefined, { sensitivity: 'base' });
      }
    });

    return sorted;
  }

  function paginateRows(list, page, perPage) {
    var total = list.length;
    var totalPages = Math.max(1, Math.ceil(total / perPage));
    var currentPage = Math.min(Math.max(page, 1), totalPages);
    var start = (currentPage - 1) * perPage;
    var end = start + perPage;

    return {
      rows: list.slice(start, end),
      page: currentPage,
      totalPages: totalPages,
      total: total
    };
  }

  function renderPagination($container, state, pageData, clickClass) {
    if (pageData.totalPages <= 1) {
      $container.html('');
      return;
    }

    var html = '';
    var i;
    var startPage = Math.max(1, pageData.page - 2);
    var endPage = Math.min(pageData.totalPages, startPage + 4);

    if (startPage > 1) {
      endPage = Math.min(pageData.totalPages, endPage + (startPage - 1));
    }

    html += '<button type="button" class="fnf-page-btn ' + clickClass + '" data-page="' + (pageData.page - 1) + '"' + (pageData.page === 1 ? ' disabled="disabled"' : '') + '>Prev</button>';

    for (i = startPage; i <= endPage; i++) {
      html += '<button type="button" class="fnf-page-btn ' + clickClass + (i === pageData.page ? ' is-active' : '') + '" data-page="' + i + '">' + i + '</button>';
    }

    html += '<button type="button" class="fnf-page-btn ' + clickClass + '" data-page="' + (pageData.page + 1) + '"' + (pageData.page === pageData.totalPages ? ' disabled="disabled"' : '') + '>Next</button>';

    $container.html(html);
  }

  function getViewPageData() {
    var filtered = filterRows(songs, viewState);
    var sorted = sortRows(filtered, viewState.sort);
    var pageData = paginateRows(sorted, viewState.page, viewState.perPage);
    viewState.page = pageData.page;
    return pageData;
  }

  function getManagePageData() {
    var filtered = filterRows(songs, manageState);
    var sorted = sortRows(filtered, manageState.sort);
    var pageData = paginateRows(sorted, manageState.page, manageState.perPage);
    manageState.page = pageData.page;
    return pageData;
  }

  function renderViewTable() {
    var pageData = getViewPageData();
    var html = '';

    if (!pageData.rows.length) {
      html = '<tr><td colspan="5"><em>' + escapeHtml(messages.noMatches) + '</em></td></tr>';
    } else {
      pageData.rows.forEach(function(row){
        html += '<tr>';
        html += '<td>' + escapeHtml(row.title) + '</td>';
        html += '<td>' + escapeHtml(row.artist) + '</td>';
        html += '<td>' + escapeHtml(row.genre) + '</td>';
        html += '<td>' + escapeHtml(row.year) + '</td>';
        html += '<td>' + escapeHtml(row.decade) + '</td>';
        html += '</tr>';
      });
    }

    $('#fnf-songs-view-body').html(html);
    $('#fnf-songs-view-count').text(pageData.total + ' result' + (pageData.total === 1 ? '' : 's'));
    renderPagination($('#fnf-songs-view-pagination'), viewState, pageData, 'fnf-view-page');
  }

  function getSelectedCount() {
    var count = 0;
    var key;
    for (key in selectedIds) {
      if (selectedIds.hasOwnProperty(key) && selectedIds[key]) {
        count++;
      }
    }
    return count;
  }

  function renderSelectedCount() {
    var count = getSelectedCount();
    var hasSelection = count > 0;

    $('#fnf-songs-selected-count').text(count + ' selected');

    $('#fnf-songs-clear-selection')
      .text(messages.deselectVisible || 'Deselect checked songs')
      .toggle(hasSelection);

    $('#fnf-songs-remove-selected')
      .text(messages.deleteSelected || 'Delete selected songs')
      .toggle(hasSelection);
  }

  function renderDuplicatePanel() {
    if (!canEdit) {
      return;
    }

    var groups = getDuplicateGroups();
    var html = '';

    if (!groups.length) {
      $('#fnf-songs-duplicates').html('');
      return;
    }

    html += '<div class="fnf-duplicates-card">';
    html += '  <div class="fnf-duplicates-title">' + escapeHtml(messages.duplicateTitleLabel) + '</div>';
    html += '  <div class="fnf-duplicates-list">';

    groups.forEach(function(group){
      html += '<div class="fnf-duplicates-item">';
      html += '  <div class="fnf-duplicates-label">' + escapeHtml(group.label) + ' (' + group.count + ')</div>';
      html += '  <div class="fnf-duplicates-actions">';
      html += '      <button type="button" class="um-button um-alt fnf-duplicate-filter-btn" data-title-key="' + escapeHtml(group.key) + '">' + escapeHtml(messages.reviewLabel || 'Review duplicates') + '</button>';
      html += '  </div>';
      html += '</div>';
    });

    html += '  </div>';

    if (manageState.duplicateTitle) {
      html += '<div class="fnf-duplicates-active-filter">';
      html += escapeHtml(messages.duplicateFilterLabel || 'Duplicate review active.') + ' <button type="button" class="fnf-clear-duplicate-filter">' + escapeHtml(messages.duplicateClearLabel || 'Show all songs') + '</button>';
      html += '</div>';
    }

    html += '</div>';

    $('#fnf-songs-duplicates').html(html);
  }

  function renderManageTable() {
    if (!canEdit) {
      return;
    }

    var pageData = getManagePageData();
    var html = '';

    if (!pageData.rows.length) {
      html = '<tr><td colspan="7"><em>' + escapeHtml(messages.noMatches) + '</em></td></tr>';
    } else {
      pageData.rows.forEach(function(row){
        var checked = selectedIds[row._rowId] ? ' checked="checked"' : '';
        html += '<tr>';
        html += '<td class="fnf-songs-col-check"><input type="checkbox" class="fnf-row-check" data-row-id="' + escapeHtml(row._rowId) + '"' + checked + ' /></td>';
        html += '<td>' + escapeHtml(row.title) + '</td>';
        html += '<td>' + escapeHtml(row.artist) + '</td>';
        html += '<td>' + escapeHtml(row.genre) + '</td>';
        html += '<td>' + escapeHtml(row.year) + '</td>';
        html += '<td>' + escapeHtml(row.decade) + '</td>';
        html += '<td class="fnf-songs-col-action"><button type="button" class="fnf-inline-remove" data-row-id="' + escapeHtml(row._rowId) + '">Remove</button></td>';
        html += '</tr>';
      });
    }

    $('#fnf-songs-manage-body').html(html);
    $('#fnf-songs-manage-count').text(pageData.total + ' result' + (pageData.total === 1 ? '' : 's'));
    renderPagination($('#fnf-songs-manage-pagination'), manageState, pageData, 'fnf-manage-page');
    renderDuplicatePanel();
    renderSelectedCount();

    var allVisibleSelected = pageData.rows.length > 0;
    pageData.rows.forEach(function(row){
      if (!selectedIds[row._rowId]) {
        allVisibleSelected = false;
      }
    });

    $('#fnf-songs-select-all-visible').prop('checked', allVisibleSelected);
  }

  function renderAll() {
    populateFilters();
    renderSummary();
    renderViewTable();
    renderManageTable();
  }

  function setMessage(text, type, showUndo) {
    var html = '';
    var cssClass = 'fnf-songs-msg-info';

    if (type === 'success') {
      cssClass = 'fnf-songs-msg-success';
    } else if (type === 'error') {
      cssClass = 'fnf-songs-msg-error';
    }

    if (text) {
      html = '<div class="' + cssClass + '">' + escapeHtml(text);
      if (showUndo && lastUndoSnapshot) {
        html += ' <a href="#" class="fnf-songs-undo">Undo</a>';
      }
      html += '</div>';
    }

    $('#fnf-songs-msg').html(html);
  }

  function scrollToElement($target, offset) {
    var topOffset = typeof offset === 'number' ? offset : 120;

    if (!$target || !$target.length) {
      return;
    }

    $('html, body').stop(true).animate({
      scrollTop: Math.max($target.offset().top - topOffset, 0)
    }, 220);
  }
    
  function getManageStickyOffset() {
    var offset = 20;
    var $adminBar = $('#wpadminbar');
    var $sticky = $('.fnf-songs-manage-sticky:visible');

    if ($adminBar.length) {
      offset += $adminBar.outerHeight();
    }

    if ($sticky.length) {
      offset += $sticky.outerHeight() + 12;
    }

    return offset;
  }

  function scrollToDuplicateReviewContext() {
    window.requestAnimationFrame(function() {
      window.requestAnimationFrame(function() {
        var $target = $('#fnf-songs-manage-count');

        if (!$target.length) {
          $target = $('#fnf-songs-manage-table');
        }

        if (!$target.length) {
          $target = $('#fnf-songs-manage-panel');
        }

        if ($target.length) {
          var extraOffset = 18;

          $('html, body').stop(true).animate({
            scrollTop: Math.max($target.offset().top - getManageStickyOffset() - extraOffset, 0)
          }, 220);
        }
      });
    });
  }
    
  function scrollToDuplicatePanelContext() {
    window.requestAnimationFrame(function() {
      window.requestAnimationFrame(function() {
        var $target = $('#fnf-songs-duplicates');

        if (!$target.length) {
          $target = $('#fnf-songs-manage-context');
        }

        if (!$target.length) {
          $target = $('#fnf-songs-manage-panel');
        }

        if ($target.length) {
          var extraOffset = -50;

          $('html, body').stop(true).animate({
            scrollTop: Math.max($target.offset().top - getManageStickyOffset() - extraOffset, 0)
          }, 220);
        }
      });
    });
  }

  function setSaveButtonsBusy(flag, label) {
    isSaving = !!flag;
    $('.fnf-songs-save-btn').prop('disabled', isSaving).toggleClass('is-busy', isSaving);
    $('.fnf-songs-save-btn').text(isSaving ? (label || messages.savingLabel || 'Saving...') : 'Save changes');
  }

  function setStarterButtonsBusy(flag) {
    $('#fnf-seed-merge, #fnf-seed-replace').prop('disabled', !!flag).toggleClass('is-busy', !!flag);
  }

  function buildStarterMergePreview() {
    var existing = {};
    var addCount = 0;
    var skipCount = 0;

    songs.forEach(function(row){
      existing[exactSongKey(row)] = true;
    });

    starterSongs.forEach(function(row){
      var key = exactSongKey(row);
      if (existing[key]) {
        skipCount++;
      } else {
        addCount++;
      }
    });

    return {
      addCount: addCount,
      skipCount: skipCount,
      currentCount: songs.length,
      starterCount: starterSongs.length
    };
  }

  function buildStarterReplacePreview() {
    var starterMap = {};
    var keepCount = 0;
    var removeCount = 0;

    starterSongs.forEach(function(row){
      starterMap[exactSongKey(row)] = true;
    });

    songs.forEach(function(row){
      if (starterMap[exactSongKey(row)]) {
        keepCount++;
      } else {
        removeCount++;
      }
    });

    return {
      currentCount: songs.length,
      starterCount: starterSongs.length,
      keepCount: keepCount,
      removeCount: removeCount
    };
  }
    
  function openStarterModal(mode) {
    var title = '';
    var body = '';
    var confirmText = '';
    var preview;

    seedActionPending = mode;

    if (mode === 'replace') {
      preview = buildStarterReplacePreview();
      title = messages.replaceModalTitle || 'Replace with curated starter songs';
      confirmText = messages.modalConfirmReplace || 'Replace my list';

      body += '<p>' + escapeHtml(messages.replaceExplainer || '') + '</p>';
      body += '<ul class="fnf-songs-modal-list">';
      body += '<li>Your current library has <strong>' + preview.currentCount + '</strong> songs.</li>';
      body += '<li>The curated starter library contains <strong>' + preview.starterCount + '</strong> songs.</li>';
      body += '<li><strong>' + preview.keepCount + '</strong> songs already match the curated library.</li>';
      body += '<li><strong>' + preview.removeCount + '</strong> songs from your current library will be removed.</li>';
      body += '<li>This action will overwrite your current list.</li>';
      body += '</ul>';
    } else {
      preview = buildStarterMergePreview();
      title = messages.mergeModalTitle || 'Add curated starter songs';
      confirmText = messages.modalConfirmMerge || 'Add curated songs';

      body += '<p>' + escapeHtml(messages.mergeExplainer || '') + '</p>';
      body += '<ul class="fnf-songs-modal-list">';
      body += '<li>Your current library has <strong>' + preview.currentCount + '</strong> songs.</li>';
      body += '<li>The curated starter library contains <strong>' + preview.starterCount + '</strong> songs.</li>';
      body += '<li><strong>' + preview.addCount + '</strong> curated songs will be added.</li>';
      body += '<li><strong>' + preview.skipCount + '</strong> exact matches already exist and will be skipped.</li>';
      body += '</ul>';
    }

    $('#fnf-songs-modal-title').text(title);
    $('#fnf-songs-modal-body').html(body);
    $('#fnf-songs-modal-confirm').text(confirmText);
    $('#fnf-songs-modal-backdrop').fadeIn(120);
    $('body').addClass('fnf-songs-modal-open');
  }

  function closeStarterModal() {
    seedActionPending = null;
    $('#fnf-songs-modal-backdrop').fadeOut(120);
    $('body').removeClass('fnf-songs-modal-open');
  }

  function applyChange(mutator, message, type) {
    lastUndoSnapshot = cloneSongs(songs);
    mutator();
    saveSongsToHidden();
    pendingActionMessage = message || '';
    setDirty(true);
    renderAll();
    setMessage('', type || 'success', false);
  }

  function clearSelection() {
    selectedIds = {};
    renderManageTable();
  }

  function removeSongsByIds(ids) {
    if (!ids.length) {
      setMessage(messages.noSongsSelected, 'error', false);
      return;
    }

    applyChange(function(){
      songs = songs.filter(function(row){
        return ids.indexOf(row._rowId) === -1;
      });
      ids.forEach(function(id){
        delete selectedIds[id];
      });
    }, ids.length === 1 ? messages.songRemoved : (ids.length + messages.songsRemovedSuffix), 'success');
  }

  function initManageSongSearch() {
    if (!canEdit || manageSearchInit) {
      return;
    }

    manageSearchInit = true;

    var $search = $('#songs_played_search');

    if (typeof $.fn.select2 !== 'function') {
      return;
    }

    $search.select2({
      width: '100%',
      placeholder: 'Type a title or artist...',
      minimumInputLength: 2,
      ajax: {
        url: config.songSearchUrl || '/wp-json/um-songs-played/v1/song-search',
        dataType: 'json',
        delay: 250,
        data: function(params){
          return { q: params.term || '', limit: 25 };
        },
        processResults: function(data){
          return data || { results: [] };
        },
        cache: true,
        beforeSend: function(xhr){
          if (restNonce) {
            xhr.setRequestHeader('X-WP-Nonce', restNonce);
          }
        }
      },
      escapeMarkup: function(m){ return m; },
      templateSelection: function(item){
        return item && item.text ? item.text : '';
      },
      templateResult: function(item){
        if (!item || item.loading) return item.text || '';
        var text = item.text || '';
        var meta = [];
        if (item.genre) meta.push(item.genre);
        if (item.decade) meta.push(item.decade);
        return text + (meta.length ? ' | ' + meta.join(' | ') : '');
      }
    });

    $search.on('select2:select', function(e){
      var d = (e.params && e.params.data) ? e.params.data : {};
      var title  = d.title || '';
      var artist = d.artist || '';

      if (!title && d.text) {
        var parts = String(d.text).split(' - ');
        title = parts[0] || '';
        artist = parts.slice(1).join(' - ');
      }

      if (!title && !artist) {
        return;
      }

      var newRow = normalizeSong({
        title: title,
        artist: artist,
        genre: d.genre || '',
        year: d.year || '',
        decade: d.decade || '',
        source: d.source || 'itunes',
        source_id: d.source_id || d.id || ''
      });

      var key = exactSongKey(newRow);
      var exists = songs.some(function(row){
        return exactSongKey(row) === key;
      });

      if (exists) {
        setMessage(messages.alreadyInList, 'error', false);
      } else {
        applyChange(function(){
          songs.push(newRow);
        }, messages.songAdded, 'success');
      }

      $search.val(null).trigger('change');
    });
  }

  function openManageMode(scrollToPanel) {
    if (!canEdit) {
      return;
    }

    manageSessionSnapshot = cloneSongs(songs);
    clearSelection();
    setDirty(false);
    setMessage('', 'info', false);
    $('#fnf-songs-view-panel').hide();
    $('#fnf-songs-manage-panel').show();
    initManageSongSearch();
    renderManageTable();

    if (scrollToPanel !== false) {
      scrollToElement($('#fnf-songs-manage-panel'), 120);
    }
  }

  function closeManageMode(restoreSnapshot) {
    if (!canEdit) {
      return;
    }

    if (restoreSnapshot && manageSessionSnapshot) {
      songs = cloneSongs(manageSessionSnapshot);
      saveSongsToHidden();
    }

    clearSelection();
    setDirty(false);
    manageState.query = '';
    manageState.genre = '';
    manageState.decade = '';
    manageState.sort = 'title_asc';
    manageState.page = 1;
    manageState.duplicateTitle = '';

    $('#fnf-songs-manage-search').val('');
    $('#fnf-songs-msg').html('');
    $('#fnf-songs-manage-panel').hide();
    $('#fnf-songs-view-panel').show();
    renderAll();
  }
    
  function saveSongsLibrary() {
    if (isSaving) {
      return;
    }

    saveSongsToHidden();
    setSaveButtonsBusy(true, messages.savingLabel || 'Saving...');

    $.ajax({
      url: config.songsSaveUrl || '/wp-json/um-songs-played/v1/songs-save',
      method: 'POST',
      data: JSON.stringify({
        user_id: profileUserId,
        value: $('#songs_played_json').val() || '[]'
      }),
      contentType: 'application/json',
      dataType: 'json',
      beforeSend: function(xhr){
        if (restNonce) {
          xhr.setRequestHeader('X-WP-Nonce', restNonce);
        }
      },
      success: function(resp){
        if (!resp || resp.ok !== true) {
          setMessage(messages.saveFailed, 'error', false);
          setSaveButtonsBusy(false);
          return;
        }

        setDirty(false);
        setMessage(messages.saveSuccessReload, 'success', false);
        location.reload();
      },
      error: function(xhr){
        var msg = getFriendlyAjaxError(xhr, messages.saveFailed);
        setMessage(msg, 'error', false);
        setSaveButtonsBusy(false);
      }
    });
  }

  function runSeed(mode) {
    setStarterButtonsBusy(true);
    setMessage(messages.seedApplying, 'info', false);

    $.ajax({
      url: config.songsSeedUrl || '/wp-json/um-songs-played/v1/songs-seed',
      method: 'POST',
      data: JSON.stringify({ mode: mode }),
      contentType: 'application/json',
      dataType: 'json',
      beforeSend: function(xhr){
        if (restNonce) {
          xhr.setRequestHeader('X-WP-Nonce', restNonce);
        }
      },
      success: function(){
        location.reload();
      },
      error: function(xhr){
        var msg = getFriendlyAjaxError(xhr, messages.seedFailed);
        setMessage(msg, 'error', false);
        setStarterButtonsBusy(false);
      }
    });
  }
    
  $(document).on('click', '.fnf-summary-card-duplicates.is-clickable', function(){
    openDuplicateReviewFromSummary();
  });

  $(document).on('keydown', '.fnf-summary-card-duplicates.is-clickable', function(e){
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      openDuplicateReviewFromSummary();
    }
  });

  $(document).on('click', '.fnf-songs-undo', function(e){
    e.preventDefault();
    if (!lastUndoSnapshot) {
      return;
    }

    songs = cloneSongs(lastUndoSnapshot);
    lastUndoSnapshot = null;
    saveSongsToHidden();
    pendingActionMessage = messages.undoDone || 'Last change undone.';
    setDirty(true);
    clearSelection();
    renderAll();
    setMessage('', 'success', false);
  });

  $('#fnf-songs-open-manage').on('click', function(){
    openManageMode(true);
  });

  $('#fnf-songs-cancel-top, #fnf-songs-cancel-bottom').on('click', function(){
    closeManageMode(true);
  });

  $('.fnf-songs-save-btn').on('click', function(){
    saveSongsLibrary();
  });

  $('#fnf-songs-view-search').on('input', function(){
    viewState.query = $(this).val() || '';
    viewState.page = 1;
    renderViewTable();
  });

  $('#fnf-songs-view-genre').on('change', function(){
    viewState.genre = $(this).val() || '';
    viewState.page = 1;
    renderViewTable();
  });

  $('#fnf-songs-view-decade').on('change', function(){
    viewState.decade = $(this).val() || '';
    viewState.page = 1;
    renderViewTable();
  });

  $('#fnf-songs-view-sort').on('change', function(){
    viewState.sort = $(this).val() || 'title_asc';
    viewState.page = 1;
    renderViewTable();
  });

  $('#fnf-songs-view-clear').on('click', function(){
    viewState.query = '';
    viewState.genre = '';
    viewState.decade = '';
    viewState.sort = 'title_asc';
    viewState.page = 1;

    $('#fnf-songs-view-search').val('');
    renderViewTable();
    populateFilters();
    $('#fnf-songs-view-sort').val('title_asc');
  });

  $('#fnf-songs-manage-search').on('input', function(){
    manageState.query = $(this).val() || '';
    manageState.page = 1;
    renderManageTable();
  });

  $('#fnf-songs-manage-genre').on('change', function(){
    manageState.genre = $(this).val() || '';
    manageState.page = 1;
    renderManageTable();
  });

  $('#fnf-songs-manage-decade').on('change', function(){
    manageState.decade = $(this).val() || '';
    manageState.page = 1;
    renderManageTable();
  });

  $('#fnf-songs-manage-sort').on('change', function(){
    manageState.sort = $(this).val() || 'title_asc';
    manageState.page = 1;
    renderManageTable();
  });

  $('#fnf-songs-manage-clear').on('click', function(){
    manageState.query = '';
    manageState.genre = '';
    manageState.decade = '';
    manageState.sort = 'title_asc';
    manageState.page = 1;
    manageState.duplicateTitle = '';

    $('#fnf-songs-manage-search').val('');
    populateFilters();
    $('#fnf-songs-manage-sort').val('title_asc');
    renderManageTable();
  });

  $(document).on('click', '.fnf-view-page', function(){
    var page = parseInt($(this).attr('data-page'), 10);
    if (isNaN(page)) return;
    viewState.page = page;
    renderViewTable();
  });

  $(document).on('click', '.fnf-manage-page', function(){
    var page = parseInt($(this).attr('data-page'), 10);
    if (isNaN(page)) return;
    manageState.page = page;
    renderManageTable();
  });

  $(document).on('change', '.fnf-row-check', function(){
    var id = $(this).attr('data-row-id');
    if (!id) return;

    if ($(this).is(':checked')) {
      selectedIds[id] = true;
    } else {
      delete selectedIds[id];
    }

    renderSelectedCount();
  });

  $('#fnf-songs-select-all-visible').on('change', function(){
    var pageData = getManagePageData();
    var checked = $(this).is(':checked');

    pageData.rows.forEach(function(row){
      if (checked) {
        selectedIds[row._rowId] = true;
      } else {
        delete selectedIds[row._rowId];
      }
    });

    renderManageTable();
    renderSelectedCount();
  });

  $('#fnf-songs-clear-selection').on('click', function(){
    clearSelection();
  });

  $('#fnf-songs-remove-selected').on('click', function(){
    var ids = [];
    var key;
    for (key in selectedIds) {
      if (selectedIds.hasOwnProperty(key) && selectedIds[key]) {
        ids.push(key);
      }
    }
    removeSongsByIds(ids);
  });

  $(document).on('click', '.fnf-inline-remove', function(){
    var id = $(this).attr('data-row-id');
    if (!id) return;
    removeSongsByIds([id]);
  });
    
  $(document).on('click', '.fnf-duplicate-filter-btn', function(){
    var duplicateKey = $(this).attr('data-title-key') || '';

    if (!canEdit) {
      return;
    }

    if ($('#fnf-songs-manage-panel').is(':hidden')) {
      openManageMode(false);
    }

    manageState.duplicateTitle = duplicateKey;
    manageState.page = 1;
    renderManageTable();
    scrollToDuplicateReviewContext();
  });

  $(document).on('click', '.fnf-clear-duplicate-filter', function(){
    manageState.duplicateTitle = '';
    manageState.page = 1;
    renderManageTable();
  });

  $('#fnf-seed-merge').on('click', function(){
    openStarterModal('merge');
  });

  $('#fnf-seed-replace').on('click', function(){
    openStarterModal('replace');
  });

  $('#fnf-songs-modal-cancel').on('click', function(){
    closeStarterModal();
  });

  $('#fnf-songs-modal-confirm').on('click', function(){
    var mode = seedActionPending;
    closeStarterModal();
    if (mode) {
      runSeed(mode);
    }
  });

  $('#fnf-songs-modal-backdrop').on('click', function(e){
    if ($(e.target).is('#fnf-songs-modal-backdrop')) {
      closeStarterModal();
    }
  });
    
  window.addEventListener('beforeunload', function(e){
    if (!isDirty || isSaving) {
      return;
    }
    e.preventDefault();
    e.returnValue = '';
  });

  loadSongsFromConfig();
  setDirty(false);
  renderAll();
  renderSelectedCount();

})(jQuery);