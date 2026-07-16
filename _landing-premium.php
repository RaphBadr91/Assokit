<?php
/**
 * _landing-premium.php — Styles + micro-interactions premium partagés par les landings
 * marketing (pour-associations, pour-tpe). Glass-morphism, orbes, reveal au scroll,
 * mockup produit. S'appuie sur les variables --c-* de public.css. NE MODIFIE PAS le reste du site.
 */
if (!defined('AK_LANDING_PREMIUM')) {
    define('AK_LANDING_PREMIUM', 1);
    ?>
<style>
/* ====== Landing premium (lp-*) ====== */
.lp-hero{position:relative;overflow:hidden;padding:96px 0 70px;isolation:isolate}
.lp-hero::before{content:"";position:absolute;inset:0;background:
  radial-gradient(60% 55% at 82% 8%, rgba(16,203,143,.22), transparent 60%),
  radial-gradient(48% 45% at 8% 22%, rgba(124,58,237,.10), transparent 60%),
  linear-gradient(180deg,#FBFEFC 0%, #F4F8F5 100%);z-index:-2}
.lp-orb{position:absolute;border-radius:50%;filter:blur(46px);opacity:.5;z-index:-1;pointer-events:none;animation:lpFloat 14s ease-in-out infinite}
.lp-orb.o1{width:340px;height:340px;top:-70px;right:-40px;background:radial-gradient(circle,#34e0a9,#05966900)}
.lp-orb.o2{width:260px;height:260px;bottom:-90px;left:-50px;background:radial-gradient(circle,#a78bfa,#7c3aed00);animation-delay:-5s}
@keyframes lpFloat{0%,100%{transform:translateY(0)}50%{transform:translateY(-22px)}}

.lp-glass{background:rgba(255,255,255,.72);backdrop-filter:blur(16px) saturate(1.4);-webkit-backdrop-filter:blur(16px) saturate(1.4);border:1px solid rgba(255,255,255,.9);box-shadow:0 1px 0 rgba(255,255,255,.7) inset, 0 18px 44px rgba(6,78,59,.10)}
.lp-badge{display:inline-flex;align-items:center;gap:8px;padding:8px 15px;border-radius:999px;font-size:13.5px;font-weight:700;color:var(--c-emeraude-dark);background:rgba(255,255,255,.7);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);border:1px solid rgba(5,150,105,.16);box-shadow:0 6px 18px rgba(5,150,105,.10)}
.lp-badge .dot{width:8px;height:8px;border-radius:50%;background:var(--c-emeraude);box-shadow:0 0 0 4px rgba(5,150,105,.18)}
.lp-grad{background:linear-gradient(120deg,#059669 0%,#0CCB8F 55%,#0891B2 100%);-webkit-background-clip:text;background-clip:text;color:transparent}

.lp-hero-cta{display:flex;gap:12px;flex-wrap:wrap;margin-top:26px}
.lp-btn{display:inline-flex;align-items:center;gap:8px;padding:14px 26px;border-radius:14px;font-size:15.5px;font-weight:700;text-decoration:none;transition:transform .18s cubic-bezier(.2,.8,.2,1),box-shadow .18s,filter .18s;will-change:transform}
.lp-btn-primary{color:#fff;background:linear-gradient(135deg,#059669,#047857);box-shadow:0 12px 26px rgba(5,150,105,.32)}
.lp-btn-primary:hover{transform:translateY(-2px);box-shadow:0 18px 36px rgba(5,150,105,.42);filter:brightness(1.04)}
.lp-btn-glass{color:var(--c-encre);background:rgba(255,255,255,.7);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);border:1px solid rgba(15,23,42,.10)}
.lp-btn-glass:hover{transform:translateY(-2px);box-shadow:0 12px 26px rgba(15,23,42,.10)}

.lp-trust{display:flex;flex-wrap:wrap;gap:10px;margin-top:22px}
.lp-trust span{display:inline-flex;align-items:center;gap:7px;font-size:13px;font-weight:600;color:var(--c-text-2);background:rgba(255,255,255,.6);border:1px solid rgba(15,23,42,.06);padding:7px 13px;border-radius:999px;backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px)}

/* Mockup produit flottant */
.lp-mock{border-radius:22px;overflow:hidden;position:relative}
.lp-mock-bar{display:flex;align-items:center;gap:7px;padding:12px 16px;background:linear-gradient(90deg,#0b3b2a,#065f46);}
.lp-mock-dot{width:11px;height:11px;border-radius:50%}
.lp-mock-title{margin-left:8px;font-size:12px;color:rgba(255,255,255,.72);font-weight:600}
.lp-mock-body{padding:18px;background:linear-gradient(180deg,#ffffff,#f7fbf9)}
.lp-mrow{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 14px;border-radius:13px;background:#fff;border:1px solid #EEF3F0;box-shadow:0 4px 14px rgba(6,78,59,.05);margin-bottom:10px}
.lp-mrow:last-child{margin-bottom:0}
.lp-mrow .ic{width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;flex:none}
.lp-mrow .t{font-size:13.5px;font-weight:700;color:var(--c-encre)}
.lp-mrow .s{font-size:11.5px;color:var(--c-text-3);margin-top:1px}
.lp-mrow .v{font-size:14px;font-weight:800;color:var(--c-emeraude-dark);white-space:nowrap}
.lp-chip{font-size:10.5px;font-weight:800;padding:4px 9px;border-radius:999px}

/* Cartes premium (douleurs / bénéfices) */
.lp-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(272px,1fr));gap:20px;max-width:1000px;margin:0 auto}
.lp-card{position:relative;background:#fff;border:1px solid var(--c-border);border-radius:20px;padding:26px;overflow:hidden;transition:transform .2s cubic-bezier(.2,.8,.2,1),box-shadow .2s,border-color .2s}
.lp-card::after{content:"";position:absolute;inset:0;border-radius:20px;background:radial-gradient(120% 80% at 100% 0%, rgba(16,203,143,.08), transparent 60%);opacity:0;transition:opacity .2s;pointer-events:none}
.lp-card:hover{transform:translateY(-6px);box-shadow:0 22px 50px rgba(6,78,59,.12);border-color:rgba(5,150,105,.28)}
.lp-card:hover::after{opacity:1}
.lp-ic{width:52px;height:52px;border-radius:15px;display:flex;align-items:center;justify-content:center;font-size:24px;margin-bottom:16px;background:linear-gradient(135deg,#ECFDF5,#D1FAE5);border:1px solid rgba(5,150,105,.14);box-shadow:0 8px 20px rgba(5,150,105,.12)}
.lp-card h3{font-size:16.5px;margin:0 0 7px;color:var(--c-encre)}
.lp-card p{font-size:14px;color:var(--c-text-2);line-height:1.58;margin:0}

/* Stat / éco block */
.lp-stat{position:relative;overflow:hidden;border-radius:26px;padding:52px 32px;text-align:center;background:linear-gradient(135deg,#052e22,#065f46 55%,#047857);color:#fff;box-shadow:0 30px 70px rgba(6,78,59,.34)}
.lp-stat::before{content:"";position:absolute;inset:0;background:radial-gradient(50% 60% at 20% 0%, rgba(16,203,143,.4), transparent 60%);opacity:.5}
.lp-stat .big{font-size:clamp(40px,8vw,68px);font-weight:900;letter-spacing:-.02em;line-height:1;background:linear-gradient(120deg,#fff,#a7f3d0);-webkit-background-clip:text;background-clip:text;color:transparent;position:relative}
.lp-stat p{position:relative;color:rgba(255,255,255,.9);max-width:600px;margin:14px auto 24px;font-size:16px;line-height:1.6}

/* Feature checklist premium */
.lp-checks{list-style:none;padding:0;max-width:760px;margin:0 auto;display:grid;grid-template-columns:1fr 1fr;gap:12px 26px}
.lp-checks li{display:flex;align-items:center;gap:10px;font-size:14.5px;color:var(--c-encre);font-weight:600}
.lp-checks li svg{flex:none;width:20px;height:20px;padding:3px;border-radius:7px;background:var(--c-emeraude-light);color:var(--c-emeraude-dark)}

/* Reveal au scroll */
.reveal{opacity:0;transform:translateY(26px);transition:opacity .7s cubic-bezier(.2,.8,.2,1),transform .7s cubic-bezier(.2,.8,.2,1)}
.reveal.in{opacity:1;transform:none}
@media (prefers-reduced-motion:reduce){.reveal{opacity:1;transform:none;transition:none}.lp-orb{animation:none}}

@media (max-width:820px){
  .lp-hero{padding:64px 0 48px}
  .lp-checks{grid-template-columns:1fr}
  .lp-hero-grid{grid-template-columns:1fr !important}
  .lp-mock-wrap{order:2;margin-top:8px}
}
</style>
<script>
(function(){
  if(!('IntersectionObserver' in window)){document.querySelectorAll('.reveal').forEach(function(e){e.classList.add('in')});return;}
  var io=new IntersectionObserver(function(en){en.forEach(function(x){if(x.isIntersecting){x.target.classList.add('in');io.unobserve(x.target);}})},{threshold:.12});
  window.addEventListener('DOMContentLoaded',function(){document.querySelectorAll('.reveal').forEach(function(e,i){e.style.transitionDelay=Math.min(i%6*70,360)+'ms';io.observe(e);})});
})();
</script>
    <?php
}
