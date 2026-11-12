@props(['name' => 'conditions', 'value', 'fields', 'previewUrl'])
{{-- 条件ビルダー（AND/OR/グループの入れ子）。編集結果は hidden の JSON に入る。 --}}
<div class="segment-builder" data-fields='@json($fields)' data-preview-url="{{ $previewUrl }}">
    <div class="seg-root"></div>
    <input type="hidden" name="{{ $name }}" class="seg-json" value="{{ json_encode($value, JSON_UNESCAPED_UNICODE) }}">
    <div class="row" style="margin-top:10px">
        <button type="button" class="btn seg-preview-btn">対象数をプレビュー</button>
        <span class="seg-preview-result muted"></span>
    </div>
    <details style="margin-top:8px"><summary class="muted">JSON を直接編集</summary>
        <textarea class="seg-raw" rows="6"></textarea>
        <button type="button" class="btn btn-sm seg-raw-apply">JSON を反映</button>
    </details>
</div>
@once
@push('scripts')
<script>
(function () {
  const OP_LABELS = {eq:'＝', neq:'≠', in:'いずれか', not_in:'いずれでもない', gt:'＞', gte:'≧', lt:'＜', lte:'≦', between:'範囲', exists:'値がある', not_exists:'値がない', within_days:'N日以内', before_days:'N日以上前'};
  function el(tag, attrs, children) {
    const e = document.createElement(tag);
    Object.entries(attrs || {}).forEach(([k, v]) => k === 'text' ? e.textContent = v : (k.startsWith('on') ? e.addEventListener(k.slice(2), v) : e.setAttribute(k, v)));
    (children || []).forEach(c => e.appendChild(c));
    return e;
  }
  document.querySelectorAll('.segment-builder').forEach(root => {
    const fields = JSON.parse(root.dataset.fields);
    const byName = Object.fromEntries(fields.map(f => [f.name, f]));
    const hidden = root.querySelector('.seg-json');
    const raw = root.querySelector('.seg-raw');
    let tree;
    try { tree = JSON.parse(hidden.value || '{}'); } catch (e) { tree = {}; }
    if (!tree.op) tree = {op: 'and', conditions: []};

    function sync() { hidden.value = JSON.stringify(tree); raw.value = JSON.stringify(tree, null, 2); }
    function valueInput(cond, field) {
      const op = cond.operator;
      if (op === 'exists' || op === 'not_exists') return el('span');
      const t = field.type;
      const set = v => { cond.value = v; sync(); };
      const scalarInput = (val, onChange) => {
        if (t === 'bool' || t === 'category') {
          const s = el('select', {onchange: e => onChange(e.target.value === 'true')}, [el('option', {value: 'true', text: 'はい / オン'}), el('option', {value: 'false', text: 'いいえ / オフ'})]);
          s.value = String(val === undefined ? true : val); if (val === undefined) onChange(true); return s;
        }
        if (field.choices) {
          const s = el('select', {onchange: e => onChange(e.target.value)}, field.choices.map(c => el('option', {value: c, text: c})));
          if (val === undefined) { onChange(field.choices[0]); } else { s.value = val; } return s;
        }
        const type = (t === 'int' || t === 'decimal') ? 'number' : 'text';
        const ph = t === 'date' ? '2026-01-31 00:00' : (t === 'version' ? '1.2.0' : '');
        return el('input', {type, value: val === undefined ? '' : val, placeholder: ph, oninput: e => onChange(type === 'number' && e.target.value !== '' ? Number(e.target.value) : e.target.value)});
      };
      if (op === 'within_days' || op === 'before_days') {
        return el('span', {}, [el('input', {type: 'number', min: 0, value: cond.value ?? '', style: 'width:80px', oninput: e => set(Number(e.target.value))}), el('span', {text: ' 日'})]);
      }
      if (op === 'between') {
        const v = Array.isArray(cond.value) ? cond.value : [undefined, undefined];
        cond.value = v;
        return el('span', {}, [scalarInput(v[0], x => { v[0] = x; set(v); }), el('span', {text: ' 〜 '}), scalarInput(v[1], x => { v[1] = x; set(v); })]);
      }
      if (op === 'in' || op === 'not_in') {
        const v = Array.isArray(cond.value) ? cond.value : [];
        return el('input', {type: 'text', value: v.join(','), placeholder: 'カンマ区切り', oninput: e => {
          const parts = e.target.value.split(',').map(s => s.trim()).filter(s => s !== '');
          set(t === 'int' || t === 'decimal' ? parts.map(Number) : parts);
        }});
      }
      return scalarInput(cond.value, set);
    }
    function renderCondition(cond, parent, idx) {
      const field = byName[cond.field] || fields[0];
      if (!cond.field && field) { cond.field = field.name; cond.operator = field.operators[0]; }
      const fieldSel = el('select', {onchange: e => { cond.field = e.target.value; cond.operator = byName[cond.field].operators[0]; delete cond.value; render(); }}, fields.map(f => el('option', {value: f.name, text: f.label})));
      fieldSel.value = cond.field;
      const opSel = el('select', {onchange: e => { cond.operator = e.target.value; delete cond.value; render(); }}, (field ? field.operators : []).map(o => el('option', {value: o, text: OP_LABELS[o] || o})));
      opSel.value = cond.operator;
      return el('div', {class: 'seg-cond'}, [fieldSel, opSel, valueInput(cond, field), el('button', {type: 'button', class: 'btn btn-sm', text: '×', onclick: () => { parent.conditions.splice(idx, 1); render(); }})]);
    }
    function renderGroup(group, parent, idx, depth) {
      const opSel = el('select', {onchange: e => { group.op = e.target.value; sync(); }}, [el('option', {value: 'and', text: 'すべて満たす（AND）'}), el('option', {value: 'or', text: 'いずれかを満たす（OR）'})]);
      opSel.value = group.op;
      const children = el('div');
      group.conditions.forEach((c, i) => children.appendChild(c.op ? renderGroup(c, group, i, depth + 1) : renderCondition(c, group, i)));
      const tools = el('div', {class: 'row', style: 'margin-top:4px'}, [
        el('button', {type: 'button', class: 'btn btn-sm', text: '＋ 条件', onclick: () => { group.conditions.push({}); render(); }}),
        depth < 4 ? el('button', {type: 'button', class: 'btn btn-sm', text: '＋ グループ', onclick: () => { group.conditions.push({op: 'and', conditions: []}); render(); }}) : el('span'),
        parent ? el('button', {type: 'button', class: 'btn btn-sm btn-danger', text: 'グループを削除', onclick: () => { parent.conditions.splice(idx, 1); render(); }}) : el('span'),
      ]);
      return el('div', {class: 'seg-group'}, [opSel, children, tools]);
    }
    function render() {
      const container = root.querySelector('.seg-root');
      container.innerHTML = '';
      container.appendChild(renderGroup(tree, null, 0, 1));
      sync();
    }
    root.querySelector('.seg-raw-apply').addEventListener('click', () => {
      try { tree = JSON.parse(raw.value); render(); } catch (e) { alert('JSON が不正です'); }
    });
    root.querySelector('.seg-preview-btn').addEventListener('click', async () => {
      const out = root.querySelector('.seg-preview-result');
      out.textContent = '計算中…';
      const res = await fetch(root.dataset.previewUrl, {method: 'POST', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content}, body: JSON.stringify({conditions: tree})});
      const json = await res.json();
      out.textContent = res.ok
        ? `対象の端末: ${json.devices.toLocaleString()} 台 ／ ユーザー: ${json.users.toLocaleString()} 人（未ログインの端末 ${json.anonymous_devices.toLocaleString()} 台）［${json.elapsed_ms} ms］`
        : 'エラー: ' + (json.errors || []).join(' / ');
    });
    render();
  });
})();
</script>
@endpush
@endonce
