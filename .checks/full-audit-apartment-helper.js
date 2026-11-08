/* Legacy apartment amounts are normalized USD when explicit USD fields exist. */
function nscApartmentMoney(row){
 const rate=Number(row.exchangeRate)>0?Number(row.exchangeRate):70,currency=row.currency==='AFN'?'AFN':'USD',explicitUSD=row.salePriceInUSD??row.totalPriceUSD,normalized=explicitUSD!==undefined&&explicitUSD!==null;
 const saleNative=Number(row.salePriceNative??(currency==='AFN'?(row.salePriceInAFN??(normalized?Z(Number(explicitUSD)*rate):row.salePrice??row.totalPrice??0)):(explicitUSD??row.salePrice??row.totalPrice??0))),saleUSD=normalized?Number(explicitUSD):Z(currency==='AFN'?saleNative/rate:saleNative);
 const currentReceived=Number(row.amountReceived??row.totalReceived??row.downPayment??0),receivedUSD=Z(normalized||currency==='USD'?currentReceived:currentReceived/rate);
 let receivedNative=Number(row.amountReceivedNative??(currency==='AFN'&&normalized?Z(currentReceived*rate):currentReceived));
 if(normalized&&Math.abs(Z(currency==='AFN'?receivedNative/rate:receivedNative)-receivedUSD)>.001)receivedNative=Z(currency==='AFN'?receivedUSD*rate:receivedUSD);
 const perM2Native=Number(row.pricePerM2Native??(currency==='AFN'&&normalized?Z(Number(row.pricePerM2||0)*rate):row.pricePerM2||0));
 return{currency,rate,saleNative:Z(saleNative),saleUSD:Z(saleUSD),receivedNative:Z(receivedNative),receivedUSD,perM2Native};
}
function nscSyncApartmentReceipt(row,receivedUSD){const before=nscApartmentMoney(row),next=Z(receivedUSD);return{amountReceived:next,totalReceived:next,amountReceivedNative:Z(before.currency==='AFN'?before.receivedNative+(next-before.receivedUSD)*before.rate:next)}}
function nscAssetUSD(row,field,rate=70){const value=Number(row[field]||0),currency=row.currency||'AFN',fx=Number(row.exchangeRate)>0?Number(row.exchangeRate):(Number(rate)>0?Number(rate):70);return Z(currency==='USD'?value:value/fx)}
