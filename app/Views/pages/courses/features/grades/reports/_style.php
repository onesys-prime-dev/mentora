<?php
/** Styles shared by the printable grade reports (plain tables: they must render in a PDF too). */
?>
<style>
    @page { margin: 18mm 14mm; }
    body { font-family: "DejaVu Sans", Arial, sans-serif; font-size: 11px; color: #1A1C1E; margin: 0; }
    h1 { font-size: 18px; color: #0040A6; margin: 0 0 2px; }
    h2 { font-size: 13px; color: #0040A6; margin: 18px 0 6px; }
    .muted { color: #646464; }
    .small { font-size: 10px; }
    table { width: 100%; border-collapse: collapse; }
    th { text-align: left; font-size: 9px; text-transform: uppercase; color: #646464; border-bottom: 1px solid #B9B9B9; padding: 4px 6px; }
    td { padding: 4px 6px; border-bottom: 1px solid #E5E5E5; vertical-align: top; }
    .num { text-align: right; white-space: nowrap; }
    .stat { width: 25%; padding: 8px 10px; border: 1px solid #D9E3F5; background: #F2F7FF; }
    .stat b { display: block; font-size: 16px; color: #0040A6; }
    .tag { font-weight: bold; }
    .bar { background: #D9E3F5; height: 9px; }
    .bar span { display: block; background: #0040A6; height: 9px; }
    .note { margin-top: 12px; padding: 7px 10px; background: #FFF8E6; border: 1px solid #FFE3A3; color: #946200; }
    .printbar { padding: 10px 14px; background: #F2F7FF; border-bottom: 1px solid #D9E3F5; }
    .printbar button { padding: 6px 14px; border: 0; border-radius: 6px; background: #0040A6; color: #fff; font-weight: bold; cursor: pointer; }
    @media print { .printbar { display: none; } }
</style>
