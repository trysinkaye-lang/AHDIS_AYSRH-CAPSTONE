(function(window, document){
  "use strict";

  if (typeof window.html2canvas === "function") return;

  function px(value, fallback) {
    const n = parseFloat(value);
    return Number.isFinite(n) ? n : fallback;
  }

  function textColor(style) {
    return style.color && style.color !== "rgba(0, 0, 0, 0)" ? style.color : "#111827";
  }

  function backgroundColor(style) {
    const bg = style.backgroundColor;
    return bg && bg !== "rgba(0, 0, 0, 0)" ? bg : "";
  }

  function drawBox(ctx, rect, rootRect, scale, style) {
    const x = (rect.left - rootRect.left) * scale;
    const y = (rect.top - rootRect.top) * scale;
    const w = rect.width * scale;
    const h = rect.height * scale;
    const bg = backgroundColor(style);

    if (bg) {
      ctx.fillStyle = bg;
      ctx.fillRect(x, y, w, h);
    }

    const borderWidth = px(style.borderTopWidth, 0);
    if (borderWidth > 0 && style.borderTopColor) {
      ctx.strokeStyle = style.borderTopColor;
      ctx.lineWidth = Math.max(1, borderWidth * scale);
      ctx.strokeRect(x, y, w, h);
    }
  }

  function drawText(ctx, element, rect, rootRect, scale, style) {
    const raw = (element.innerText || element.textContent || "").replace(/\s+/g, " ").trim();
    if (!raw) return;
    if (element.children && element.children.length > 0 && element.tagName !== "TH" && element.tagName !== "TD") return;

    const fontSize = px(style.fontSize, 13) * scale;
    const weight = style.fontWeight || "400";
    const family = style.fontFamily || "Arial, sans-serif";
    const lineHeight = Math.max(fontSize * 1.25, px(style.lineHeight, fontSize * 1.25) * scale);
    const left = (rect.left - rootRect.left + px(style.paddingLeft, 0)) * scale;
    let top = (rect.top - rootRect.top + px(style.paddingTop, 0)) * scale + fontSize;
    const maxWidth = Math.max(20, (rect.width - px(style.paddingLeft, 0) - px(style.paddingRight, 0)) * scale);

    ctx.fillStyle = textColor(style);
    ctx.font = weight + " " + fontSize + "px " + family;
    ctx.textBaseline = "alphabetic";

    const words = raw.split(" ");
    let line = "";
    for (let i = 0; i < words.length; i++) {
      const test = line ? line + " " + words[i] : words[i];
      if (ctx.measureText(test).width > maxWidth && line) {
        ctx.fillText(line, left, top);
        line = words[i];
        top += lineHeight;
      } else {
        line = test;
      }
    }
    if (line) ctx.fillText(line, left, top);
  }

  function walkVisible(element, callback) {
    if (!element || element.nodeType !== 1) return;
    const style = window.getComputedStyle(element);
    if (style.display === "none" || style.visibility === "hidden" || Number(style.opacity) === 0) return;
    callback(element, style);
    Array.prototype.forEach.call(element.children || [], function(child){
      walkVisible(child, callback);
    });
  }

  window.html2canvas = function(element, options) {
    options = options || {};
    return new Promise(function(resolve, reject){
      try {
        if (!element || !element.getBoundingClientRect) {
          reject(new Error("html2canvas.local: invalid element"));
          return;
        }

        const scale = Math.max(1, Number(options.scale || 1));
        const rootRect = element.getBoundingClientRect();
        const width = Math.max(element.scrollWidth, Math.ceil(rootRect.width), 1);
        const height = Math.max(element.scrollHeight, Math.ceil(rootRect.height), 1);
        const canvas = document.createElement("canvas");
        canvas.width = Math.ceil(width * scale);
        canvas.height = Math.ceil(height * scale);

        const ctx = canvas.getContext("2d");
        ctx.fillStyle = options.backgroundColor || "#ffffff";
        ctx.fillRect(0, 0, canvas.width, canvas.height);

        walkVisible(element, function(node, style){
          const rect = node.getBoundingClientRect();
          if (rect.width <= 0 || rect.height <= 0) return;
          drawBox(ctx, rect, rootRect, scale, style);
          drawText(ctx, node, rect, rootRect, scale, style);
        });

        resolve(canvas);
      } catch (error) {
        reject(error);
      }
    });
  };
})(window, document);
