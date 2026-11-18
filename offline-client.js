/* Offline / LAN browser fallbacks.
 *
 * crypto.randomUUID() and crypto.subtle are only exposed in a secure context
 * (https:// or localhost). A workshop laptop reached as http://192.168.x.x/ is not
 * one, so the workspace module would throw while generating a tab id, and the file
 * save path would fail while hashing an attachment. Neither fallback weakens the
 * public host: on the normal HTTPS deployment the native APIs still win.
 */
(() => {
  'use strict';

  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID !== 'function') {
    try {
      Object.defineProperty(crypto, 'randomUUID', {
        configurable: true,
        writable: true,
        value: function randomUUID() {
          if (typeof crypto.getRandomValues === 'function') {
            const bytes = crypto.getRandomValues(new Uint8Array(16));
            bytes[6] = (bytes[6] & 0x0f) | 0x40; // version 4
            bytes[8] = (bytes[8] & 0x3f) | 0x80; // variant 10
            const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
            return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
          }
          // Last resort: only used for a per-tab identifier, never for a credential.
          return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
            const r = (Math.random() * 16) | 0;
            return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
          });
        },
      });
    } catch (_) { /* frozen crypto: the native API is either present or unreachable */ }
  }

  // crypto.subtle.digest('SHA-256') fallback. Used only to fingerprint an attachment
  // so the server can match the upload with its reference — not as a password hash.
  if (typeof crypto !== 'undefined' && !crypto.subtle) {
    const K = [
      0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
      0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
      0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
      0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
      0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
      0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
      0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
      0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2,
    ];
    const rotr = (x, n) => (x >>> n) | (x << (32 - n));

    function sha256(bytes) {
      const l = bytes.length;
      const withOne = new Uint8Array(((l + 9 + 63) >> 6) << 6);
      withOne.set(bytes);
      withOne[l] = 0x80;
      const bits = l * 8;
      const view = new DataView(withOne.buffer);
      view.setUint32(withOne.length - 8, Math.floor(bits / 0x100000000));
      view.setUint32(withOne.length - 4, bits >>> 0);

      const H = [0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19];
      const W = new Uint32Array(64);
      for (let off = 0; off < withOne.length; off += 64) {
        for (let i = 0; i < 16; i++) W[i] = view.getUint32(off + i * 4);
        for (let i = 16; i < 64; i++) {
          const s0 = rotr(W[i - 15], 7) ^ rotr(W[i - 15], 18) ^ (W[i - 15] >>> 3);
          const s1 = rotr(W[i - 2], 17) ^ rotr(W[i - 2], 19) ^ (W[i - 2] >>> 10);
          W[i] = (W[i - 16] + s0 + W[i - 7] + s1) >>> 0;
        }
        let [a, b, c, d, e, f, g, h] = H;
        for (let i = 0; i < 64; i++) {
          const S1 = rotr(e, 6) ^ rotr(e, 11) ^ rotr(e, 25);
          const ch = (e & f) ^ (~e & g);
          const t1 = (h + S1 + ch + K[i] + W[i]) >>> 0;
          const S0 = rotr(a, 2) ^ rotr(a, 13) ^ rotr(a, 22);
          const maj = (a & b) ^ (a & c) ^ (b & c);
          const t2 = (S0 + maj) >>> 0;
          h = g; g = f; f = e; e = (d + t1) >>> 0;
          d = c; c = b; b = a; a = (t1 + t2) >>> 0;
        }
        H[0] = (H[0] + a) >>> 0; H[1] = (H[1] + b) >>> 0; H[2] = (H[2] + c) >>> 0; H[3] = (H[3] + d) >>> 0;
        H[4] = (H[4] + e) >>> 0; H[5] = (H[5] + f) >>> 0; H[6] = (H[6] + g) >>> 0; H[7] = (H[7] + h) >>> 0;
      }
      const out = new Uint8Array(32);
      const outView = new DataView(out.buffer);
      H.forEach((value, i) => outView.setUint32(i * 4, value));
      return out;
    }

    const subtle = {
      digest(algorithm, data) {
        const name = typeof algorithm === 'string' ? algorithm : algorithm && algorithm.name;
        if (String(name).toUpperCase().replace('-', '') !== 'SHA256') {
          return Promise.reject(new Error('Only SHA-256 is available in offline mode.'));
        }
        return Promise.resolve().then(() => {
          const bytes = data instanceof ArrayBuffer
            ? new Uint8Array(data)
            : new Uint8Array(data.buffer, data.byteOffset, data.byteLength);
          return sha256(bytes).buffer;
        });
      },
    };
    try {
      Object.defineProperty(crypto, 'subtle', { configurable: true, value: subtle });
    } catch (_) { /* ignore */ }
  }
})();
